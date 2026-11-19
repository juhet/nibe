<?php

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/dashboard.php';

function nibe_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$measurements = [];
$connections = [];
$databaseReady = true;

try {
    $database = nibe_database();
    $query = <<<'SQL'
        SELECT d.display_name AS device_name, d.source_device_id, p.name, p.unit, p.source_point_id, o.raw_value,
               p.id AS measurement_point_id, o.source_timestamp, o.collected_at
        FROM nibe_measurement_points p
        INNER JOIN nibe_devices d ON d.id = p.device_id
        LEFT JOIN nibe_observations o ON o.id = (
            SELECT latest.id
            FROM nibe_observations latest
            WHERE latest.measurement_point_id = p.id
            ORDER BY latest.collected_at DESC, latest.id DESC
            LIMIT 1
        )
        WHERE p.enabled = 1
        ORDER BY d.display_name, p.name
        SQL;
    $statement = $database->prepare($query);
    $statement->execute();
    $measurements = $statement->fetchAll();
    $defaultRoleMapping = nibe_dashboard_default_role_mapping($measurements);

    $connectionStatement = $database->prepare(
        'SELECT source, authorization_status, last_success_at
         FROM nibe_connections
         ORDER BY source
         LIMIT 20'
    );
    $connectionStatement->execute();
    $connections = $connectionStatement->fetchAll();
} catch (Throwable $exception) {
    $databaseReady = false;
}

$installationLabel = nibe_env('NIBE_INSTALLATION_LABEL', 'NIBE-installatie');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= nibe_escape($installationLabel) ?> — dashboard</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #17212b; background: #f3f6f8; }
        body { margin: 0 auto; max-width: 90rem; padding: 2rem 1rem; }
        h1 { margin-bottom: .35rem; }
        .muted { color: #526170; }
        .notice { padding: 1rem; border-radius: .5rem; background: #fff3cd; }
        .measurements { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr)); gap: 1rem; margin-top: 1.5rem; }
        .measurement { min-width: 0; padding: 1rem; background: white; border-radius: .5rem; box-shadow: 0 1px 3px #17212b12; }
        .measurement h2 { margin: 0; font-size: 1rem; }
        .measurement .device { margin: .25rem 0 .75rem; }
        .value { margin: .5rem 0; font-size: 1.8rem; font-weight: 650; overflow-wrap: anywhere; }
        .unit { font-size: 1rem; font-weight: 400; }
        .trend { display: block; width: 100%; height: 3rem; margin: .5rem 0; }
        .status { font-weight: 600; }
        .status.stale { color: #8a4b00; }
        .connections { margin-top: 1.5rem; padding: 1rem; background: white; border-radius: .5rem; }
        .connections h2 { margin: 0 0 .5rem; font-size: 1rem; }
        .connections ul { margin: 0; padding-left: 1.25rem; }
        .panel { margin-top: 1.5rem; padding: 1.25rem; background: white; border-radius: .5rem; }
        .roles { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; }
        .role { padding: .8rem; background: #f3f6f8; border-radius: .5rem; min-width: 0; }
        .role label { font-weight: 650; display: block; }
        select, input, button { font: inherit; max-width: 100%; padding: .4rem; }
        .role select { width: 100%; }
        .controls { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; }
        .controls label { display: grid; gap: .25rem; }
        .point-select { width: 100%; min-height: 10rem; }
        .chart { width: 100%; height: auto; min-height: 15rem; overflow: visible; }
        .chart text { font-size: 13px; fill: #34495a; }
        .legend { display: flex; gap: 1rem; flex-wrap: wrap; padding: 0; list-style: none; }
        .legend span { display: inline-block; width: 1em; height: .2em; vertical-align: middle; }
        summary { cursor: pointer; font-weight: 650; }
    </style>
</head>
<body>
    <h1><?= nibe_escape($installationLabel) ?></h1>
    <p class="muted">Alle tijden zijn UTC. Dit dashboard wijzigt geen instellingen van de warmtepomp.</p>

    <?php if ($databaseReady && $connections !== []): ?>
        <section class="connections" aria-label="Verbindingsstatus">
            <h2>Verbindingen</h2>
            <ul>
                <?php foreach ($connections as $connection): ?>
                    <?php
                    $connectionStatus = match ($connection['authorization_status']) {
                        'authorized' => 'Verbonden',
                        'error' => 'Fout',
                        'not_configured' => 'Niet ingesteld',
                        default => $connection['authorization_status'],
                    };
                    ?>
                    <li>
                        <strong><?= nibe_escape($connection['source']) ?>:</strong>
                        <?= nibe_escape($connectionStatus) ?>.
                        Laatste geslaagde verzameling (UTC):
                        <?= $connection['last_success_at'] === null ? 'Geen' : nibe_escape($connection['last_success_at']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if (!$databaseReady): ?>
        <p class="notice">De database is nog niet beschikbaar. Controleer de configuratie en installatie-instructies.</p>
    <?php elseif ($measurements === []): ?>
        <p class="notice">Er zijn nog geen meetpunten of metingen beschikbaar.</p>
    <?php else: ?>
        <p class="muted">Verouderd betekent dat de brontijd (of, als die ontbreekt, de verzameltijd) meer dan 24 uur oud is. Een oude brontijd kan ook betekenen dat een waarde niet is gewijzigd.</p>
        <section class="panel" aria-labelledby="important-title">
            <h2 id="important-title">Belangrijkste metingen</h2>
            <p class="muted">Bekende meetpunten worden automatisch gekoppeld. Je kunt de rollen aanpassen; keuzes worden alleen in deze browser bewaard.</p>
            <div class="roles">
                <?php foreach ([
                    'indoor' => 'Temperatuur in huis',
                    'hot_water' => 'Temperatuur warm tapwater',
                    'outdoor' => 'Buitentemperatuur',
                    'degree_minutes' => 'Graadminuten',
                    'frequency' => 'Huidige compressorfrequentie',
                    'calculated_supply' => 'Berekende toevoer klimaatsysteem',
                    'supply' => 'Aanvoer',
                    'return' => 'Retour',
                    'runtime' => 'Bedrijfstijd',
                    'starts' => 'Aantal starts',
                    'auxiliary' => 'Bijverwarming elektrisch element',
                ] as $role => $label): ?>
                    <?php $defaultPointId = $defaultRoleMapping[$role] ?? null; ?>
                    <div class="role">
                        <label for="role-<?= nibe_escape($role) ?>"><?= nibe_escape($label) ?></label>
                        <select id="role-<?= nibe_escape($role) ?>" class="role-select" data-role="<?= nibe_escape($role) ?>">
                            <option value="">Niet ingesteld</option>
                            <?php foreach ($measurements as $measurement): ?>
                                <option value="<?= nibe_escape((string) $measurement['measurement_point_id']) ?>"
                                    <?= (string) $measurement['measurement_point_id'] === $defaultPointId ? 'selected' : '' ?>
                                    data-value="<?= nibe_escape($measurement['raw_value']) ?>"
                                    data-has-value="<?= $measurement['raw_value'] === null ? '0' : '1' ?>"
                                    data-unit="<?= nibe_escape($measurement['unit']) ?>"
                                    data-source-time="<?= nibe_escape($measurement['source_timestamp']) ?>"
                                    data-collected-at="<?= nibe_escape($measurement['collected_at']) ?>">
                                    <?= nibe_escape($measurement['name'] . ' — ' . $measurement['device_name'] . ' [' . $measurement['source_point_id'] . ']') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="value role-value">Niet ingesteld</p>
                        <p class="muted role-time"></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel" aria-labelledby="chart-title">
            <h2 id="chart-title">Metingen vergelijken</h2>
            <p class="muted">De beschikbare standaardmeetpunten worden automatisch getoond; je kunt maximaal acht meetpunten kiezen (Ctrl of ⌘ voor meerdere). Verschillende eenheden krijgen een eigen grafiek met dezelfde tijdas. Lijnen zijn een visuele verbinding; waarden tussen metingen worden bij vergelijken als schatting getoond.</p>
            <label for="chart-points">Meetpunten</label>
            <select id="chart-points" class="point-select" multiple>
                <?php foreach ($measurements as $measurement): ?>
                    <option value="<?= nibe_escape((string) $measurement['measurement_point_id']) ?>"
                        <?= in_array((string) $measurement['measurement_point_id'], array_values($defaultRoleMapping), true) ? 'selected' : '' ?>>
                        <?= nibe_escape(nibe_dashboard_point_name($measurement) . ' — ' . $measurement['device_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p><button type="button" id="chart-use-roles">Gebruik ingestelde metingen</button></p>
            <div class="controls">
                <label>Periode
                    <select id="chart-period">
                        <option value="1">24 uur</option>
                        <option value="7">7 dagen</option>
                        <option value="30">30 dagen</option>
                        <option value="custom">Zelf kiezen (UTC)</option>
                    </select>
                </label>
                <label>Van (UTC)<input type="datetime-local" id="chart-start" disabled></label>
                <label>Tot (UTC)<input type="datetime-local" id="chart-end" disabled></label>
                <button type="button" id="chart-show">Toon grafiek</button>
            </div>
            <p id="chart-message" class="muted" role="status">Kies meetpunten en een periode.</p>
            <div id="charts"></div>
            <noscript>Voor het kiezen van meetpunten en het tonen van grafieken is JavaScript nodig.</noscript>
        </section>
        <details class="panel">
        <summary>Alle meetpunten (<?= count($measurements) ?>)</summary>
        <section class="measurements" aria-label="Laatste meetwaarden">
            <?php foreach ($measurements as $measurement): ?>
                <?php
                $isStale = false;
                $freshnessTimestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'];
                if ($freshnessTimestamp !== null) {
                    $observedAt = new DateTimeImmutable($freshnessTimestamp, new DateTimeZone('UTC'));
                    $isStale = $observedAt < $now->modify('-24 hours');
                }
                ?>
                <article class="measurement">
                    <h2><?= nibe_escape($measurement['name']) ?></h2>
                    <p class="device muted"><?= nibe_escape($measurement['device_name']) ?> · Bron-ID: <?= nibe_escape($measurement['source_point_id']) ?></p>
                    <p class="value">
                        <?= $measurement['raw_value'] === null ? 'Geen meting' : nibe_escape($measurement['raw_value']) ?>
                        <?php if ($measurement['unit'] !== null && $measurement['unit'] !== ''): ?>
                            <span class="unit"><?= nibe_escape($measurement['unit']) ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="muted">Bron tijd (UTC): <?= $measurement['source_timestamp'] === null ? 'Niet aangeleverd' : nibe_escape($measurement['source_timestamp']) ?></p>
                    <p class="muted">Verzameld (UTC): <?= $measurement['collected_at'] === null ? 'Niet beschikbaar' : nibe_escape($measurement['collected_at']) ?></p>
                    <p class="status<?= $isStale ? ' stale' : '' ?>">
                        <?= $freshnessTimestamp === null ? 'Geen meting beschikbaar' : ($isStale ? 'Verouderd (>24 uur)' : 'Binnen 24 uur') ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </section>
        </details>
        <script src="dashboard.js" defer></script>
    <?php endif; ?>
</body>
</html>
