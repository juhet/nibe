<?php

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/dashboard.php';

function nibe_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$measurements = [];
$connections = [];
$defaultRoleMapping = [];
$historyByRole = [];
$kpis = ['compressor_starts' => null, 'average_delta_t' => null, 'average_run_seconds' => null];
$databaseReady = true;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

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
    $measurementById = [];
    foreach ($measurements as $measurement) {
        $measurementById[(string) $measurement['measurement_point_id']] = $measurement;
    }

    $historyRoles = ['frequency', 'supply', 'return'];
    $historyIds = [];
    foreach ($historyRoles as $role) {
        if (isset($defaultRoleMapping[$role])) {
            $historyIds[$role] = $defaultRoleMapping[$role];
        }
    }
    if ($historyIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($historyIds), '?'));
        $historyStart = $now->modify('-24 hours')->format('Y-m-d H:i:s');
        $historyEnd = $now->format('Y-m-d H:i:s');
        $historyStatement = $database->prepare(
            'SELECT measurement_point_id, raw_value, source_timestamp, collected_at
             FROM nibe_observations
             WHERE measurement_point_id IN (' . $placeholders . ')
               AND ((source_timestamp >= ? AND source_timestamp <= ?)
                    OR (source_timestamp IS NULL AND collected_at >= ? AND collected_at <= ?))
             ORDER BY COALESCE(source_timestamp, collected_at), id'
        );
        $historyStatement->execute([
            ...array_values($historyIds),
            $historyStart,
            $historyEnd,
            $historyStart,
            $historyEnd,
        ]);
        foreach ($historyStatement->fetchAll() as $observation) {
            foreach ($historyIds as $role => $measurementId) {
                if ((string) $observation['measurement_point_id'] === $measurementId) {
                    $historyByRole[$role][] = $observation;
                    break;
                }
            }
        }

        if (isset($historyIds['frequency'])) {
            $baselineStatement = $database->prepare(
                'SELECT raw_value, source_timestamp, collected_at
                 FROM nibe_observations
                 WHERE measurement_point_id = ?
                   AND (source_timestamp < ? OR (source_timestamp IS NULL AND collected_at < ?))
                 ORDER BY COALESCE(source_timestamp, collected_at) DESC, id DESC
                 LIMIT 1'
            );
            $baselineStatement->execute([$historyIds['frequency'], $historyStart, $historyStart]);
            $baseline = $baselineStatement->fetch();
            if ($baseline !== false) {
                $historyByRole['frequency'][] = $baseline;
            }
        }
    }
    $supplyUnit = $measurementById[$defaultRoleMapping['supply'] ?? '']['unit'] ?? '';
    $returnUnit = $measurementById[$defaultRoleMapping['return'] ?? '']['unit'] ?? '';
    $kpis = nibe_dashboard_kpis($historyByRole, (string) $supplyUnit, (string) $returnUnit, $now);

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
$importantRoles = [
    'indoor' => 'Temperatuur in huis',
    'hot_water' => 'Temperatuur warm tapwater',
    'outdoor' => 'Buitentemperatuur',
    'degree_minutes' => 'Graadminuten',
    'frequency' => 'Compressorfrequentie',
    'calculated_supply' => 'Berekende toevoer',
    'supply' => 'Aanvoer',
    'return' => 'Retour',
];
$chartRoleOrder = ['hot_water', 'calculated_supply', 'supply', 'return', 'indoor', 'outdoor'];
$chartPointIds = array_values(array_filter(array_map(
    static fn (string $role): ?string => $defaultRoleMapping[$role] ?? null,
    $chartRoleOrder
)));
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
        .measurement { min-width: 0; padding: .75rem; background: white; border-radius: .5rem; box-shadow: 0 1px 3px #17212b12; }
        .measurement h2 { margin: 0; font-size: 1rem; }
        .measurement .device { margin: .25rem 0 .75rem; }
        .value { margin: .5rem 0; font-size: 1.8rem; font-weight: 650; overflow-wrap: anywhere; }
        .unit { font-size: 1rem; font-weight: 400; }
        .status { display: inline-flex; align-items: center; gap: .4rem; margin: .25rem 0 0; font-size: .9rem; font-weight: 600; }
        .status::before { width: .65rem; height: .65rem; border-radius: 50%; background: #b42318; content: ""; }
        .status.live::before { background: #188038; }
        .status.stale { color: #8a4b00; }
        .status.stale::before { background: #d97706; }
        .important-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: .5rem 1rem; }
        .important-item { min-width: 0; padding: .55rem .7rem; background: #f3f6f8; border-radius: .4rem; }
        .important-item h3 { margin: 0; font-size: .9rem; }
        .important-item .value { margin: .15rem 0; font-size: 1.25rem; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: .5rem 1rem; margin-top: 1rem; }
        .kpi { padding: .65rem .75rem; background: #eaf2f4; border-radius: .4rem; }
        .kpi strong { display: block; font-size: 1.2rem; }
        .connections { margin-top: 1.5rem; padding: 1rem; background: white; border-radius: .5rem; }
        .connections h2 { margin: 0 0 .5rem; font-size: 1rem; }
        .connections ul { margin: 0; padding-left: 1.25rem; }
        .panel { margin-top: 1.5rem; padding: 1.25rem; background: white; border-radius: .5rem; }
        select, input, button { font: inherit; max-width: 100%; padding: .4rem; }
        .controls { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; }
        .controls label { display: grid; gap: .25rem; }
        .chart { width: 100%; height: auto; min-height: 15rem; overflow: visible; }
        .chart text { font-size: 13px; fill: #34495a; }
        .legend { display: flex; gap: 1rem; flex-wrap: wrap; padding: 0; list-style: none; }
        .legend span { display: inline-block; width: 1em; height: .2em; vertical-align: middle; }
        .comparison { white-space: pre-line; }
        summary { cursor: pointer; font-weight: 650; }
    </style>
</head>
<body>
    <h1><?= nibe_escape($installationLabel) ?></h1>
    <p class="muted">Tijden worden weergegeven in de tijdzone van je browser. Dit dashboard wijzigt geen instellingen van de warmtepomp.</p>

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
                        Laatste geslaagde verzameling:
                        <?= $connection['last_success_at'] === null
                            ? 'Geen'
                            : '<time class="local-time" datetime="' . nibe_escape($connection['last_success_at']) . '">' . nibe_escape($connection['last_success_at']) . '</time>' ?>
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
        <section class="panel" aria-labelledby="important-title">
            <h2 id="important-title">Belangrijkste metingen</h2>
            <div class="important-grid">
                <?php foreach ($importantRoles as $role => $label): ?>
                    <?php if (isset($defaultRoleMapping[$role], $measurementById[$defaultRoleMapping[$role]])): ?>
                        <?php
                        $measurement = $measurementById[$defaultRoleMapping[$role]];
                        $freshnessTimestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'];
                        $isEmpty = $measurement['raw_value'] === null || $freshnessTimestamp === null;
                        $isStale = !$isEmpty
                            && new DateTimeImmutable($freshnessTimestamp, new DateTimeZone('UTC')) < $now->modify('-24 hours');
                        $statusClass = $isEmpty ? '' : ($isStale ? ' stale' : ' live');
                        $statusLabel = $isEmpty ? 'Geen meting' : ($isStale ? 'Ouder dan 24 uur' : 'Live');
                        ?>
                        <article class="important-item">
                            <h3><?= nibe_escape($label) ?></h3>
                            <p class="value">
                                <?= $isEmpty ? '—' : nibe_escape($measurement['raw_value']) ?>
                                <?php if (!$isEmpty && $measurement['unit'] !== null && $measurement['unit'] !== ''): ?>
                                    <span class="unit"><?= nibe_escape($measurement['unit']) ?></span>
                                <?php endif; ?>
                            </p>
                            <p class="status<?= $statusClass ?>"><?= nibe_escape($statusLabel) ?></p>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="muted">Kerncijfers afgelopen 24 uur</p>
            <div class="kpis" aria-label="Kerncijfers afgelopen 24 uur">
                <div class="kpi">Compressorstarts<strong><?= $kpis['compressor_starts'] === null ? '—' : nibe_escape((string) $kpis['compressor_starts']) ?></strong></div>
                <div class="kpi">Gemiddelde ΔT<strong><?= $kpis['average_delta_t'] === null ? '—' : nibe_escape(number_format($kpis['average_delta_t'], 1, ',', '') . ' ' . $supplyUnit) ?></strong></div>
                <div class="kpi">Gemiddelde compressor-runduur<strong><?= $kpis['average_run_seconds'] === null ? '—' : nibe_escape(number_format($kpis['average_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
            </div>
        </section>
        <section class="panel" aria-labelledby="chart-title">
            <h2 id="chart-title">Metingen vergelijken</h2>
            <p class="muted">De standaardtemperaturen worden automatisch getoond. Verschillende eenheden krijgen een eigen grafiek; waarden tussen metingen worden als schatting getoond.</p>
            <div class="controls">
                <label>Periode
                    <select id="chart-period">
                        <option value="4h" selected>4 uur</option>
                        <option value="1">24 uur</option>
                        <option value="7">7 dagen</option>
                        <option value="30">30 dagen</option>
                        <option value="custom">Zelf kiezen</option>
                    </select>
                </label>
                <label>Van<input type="datetime-local" id="chart-start" disabled></label>
                <label>Tot<input type="datetime-local" id="chart-end" disabled></label>
                <button type="button" id="chart-show">Toon grafiek</button>
            </div>
            <p id="chart-message" class="muted" role="status">Grafiek laden…</p>
            <div id="charts" data-points="<?= nibe_escape(implode(',', $chartPointIds)) ?>"></div>
            <noscript>Voor het tonen van grafieken is JavaScript nodig.</noscript>
        </section>
        <details class="panel">
        <summary>Alle meetpunten (<?= count($measurements) ?>)</summary>
        <section class="measurements" aria-label="Laatste meetwaarden">
            <?php foreach ($measurements as $measurement): ?>
                <?php
                $freshnessTimestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'];
                $isEmpty = $measurement['raw_value'] === null || $freshnessTimestamp === null;
                $isStale = !$isEmpty
                    && new DateTimeImmutable($freshnessTimestamp, new DateTimeZone('UTC')) < $now->modify('-24 hours');
                ?>
                <article class="measurement">
                    <h2><?= nibe_escape($measurement['name']) ?></h2>
                    <p class="device muted"><?= nibe_escape($measurement['device_name']) ?> · Bron-ID: <?= nibe_escape($measurement['source_point_id']) ?></p>
                    <p class="value">
                        <?= $measurement['raw_value'] === null ? 'Geen meting' : nibe_escape($measurement['raw_value']) ?>
                        <?php if (!$isEmpty && $measurement['unit'] !== null && $measurement['unit'] !== ''): ?>
                            <span class="unit"><?= nibe_escape($measurement['unit']) ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="status<?= $isEmpty ? '' : ($isStale ? ' stale' : ' live') ?>">
                        <?= $isEmpty ? 'Geen meting' : ($isStale ? 'Ouder dan 24 uur' : 'Live') ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </section>
        </details>
    <?php endif; ?>
    <script src="dashboard.js" defer></script>
</body>
</html>
