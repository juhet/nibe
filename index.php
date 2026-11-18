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
        SELECT d.display_name AS device_name, p.name, p.unit, o.raw_value,
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
        LIMIT 100
        SQL;
    $statement = $database->prepare($query);
    $statement->execute();
    $measurements = $statement->fetchAll();

    $historyQuery = <<<'SQL'
        SELECT o.measurement_point_id, o.raw_value, o.source_timestamp, o.collected_at
        FROM nibe_observations o
        INNER JOIN nibe_measurement_points p ON p.id = o.measurement_point_id
        WHERE p.enabled = 1
          AND o.collected_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
        ORDER BY o.measurement_point_id, o.collected_at DESC, o.id DESC
        LIMIT 5000
        SQL;
    $historyStatement = $database->prepare($historyQuery);
    $historyStatement->execute();
    foreach ($historyStatement->fetchAll() as $observation) {
        $historyByPoint[$observation['measurement_point_id']][] = $observation;
    }

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

$historyByPoint ??= [];
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
        body { margin: 0 auto; max-width: 70rem; padding: 2rem 1rem; }
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
        <p class="muted">Verouderd betekent dat de bron- of verzameltijd meer dan 24 uur oud is. Grafieken tonen numerieke waarnemingen uit de afgelopen 24 uur; andere waarden blijven zichtbaar als laatste waarde.</p>
        <section class="measurements" aria-label="Laatste meetwaarden">
            <?php foreach ($measurements as $measurement): ?>
                <?php
                $pointHistory = $historyByPoint[$measurement['measurement_point_id']] ?? [];
                $isStale = false;
                $freshnessTimestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'];
                if ($freshnessTimestamp !== null) {
                    $observedAt = new DateTimeImmutable($freshnessTimestamp, new DateTimeZone('UTC'));
                    $isStale = $observedAt < $now->modify('-24 hours');
                }
                ?>
                <article class="measurement">
                    <h2><?= nibe_escape($measurement['name']) ?></h2>
                    <p class="device muted"><?= nibe_escape($measurement['device_name']) ?></p>
                    <p class="value">
                        <?= $measurement['raw_value'] === null ? 'Geen meting' : nibe_escape($measurement['raw_value']) ?>
                        <?php if ($measurement['unit'] !== null && $measurement['unit'] !== ''): ?>
                            <span class="unit"><?= nibe_escape($measurement['unit']) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if (($trend = nibe_dashboard_sparkline($pointHistory, $measurement['unit'])) !== null): ?>
                        <?= $trend ?>
                        <p class="muted">Numerieke waarnemingen, laatste 24 uur</p>
                    <?php endif; ?>
                    <p class="muted">Bron tijd (UTC): <?= $measurement['source_timestamp'] === null ? 'Niet aangeleverd' : nibe_escape($measurement['source_timestamp']) ?></p>
                    <p class="muted">Verzameld (UTC): <?= $measurement['collected_at'] === null ? 'Niet beschikbaar' : nibe_escape($measurement['collected_at']) ?></p>
                    <p class="status<?= $isStale ? ' stale' : '' ?>">
                        <?= $freshnessTimestamp === null ? 'Geen meting beschikbaar' : ($isStale ? 'Verouderd (>24 uur)' : 'Binnen 24 uur') ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</body>
</html>
