<?php

require_once __DIR__ . '/app/config.php';

function nibe_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$measurements = [];
$databaseReady = true;

try {
    $database = nibe_database();
    $query = <<<'SQL'
        SELECT d.display_name AS device_name, p.name, p.unit, o.raw_value,
               o.source_timestamp, o.collected_at
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
} catch (Throwable $exception) {
    $databaseReady = false;
}

$installationLabel = nibe_env('NIBE_INSTALLATION_LABEL', 'NIBE-installatie');
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
        .table-wrap { overflow-x: auto; margin-top: 1.5rem; background: white; border-radius: .5rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .8rem; text-align: left; border-bottom: 1px solid #e1e7eb; }
        th { white-space: nowrap; }
        @media (max-width: 40rem) { body { padding: 1rem .75rem; } th, td { padding: .6rem; } }
    </style>
</head>
<body>
    <h1><?= nibe_escape($installationLabel) ?></h1>
    <p class="muted">Alle tijden zijn UTC. Dit dashboard wijzigt geen instellingen van de warmtepomp.</p>

    <?php if (!$databaseReady): ?>
        <p class="notice">De database is nog niet beschikbaar. Controleer de configuratie en installatie-instructies.</p>
    <?php elseif ($measurements === []): ?>
        <p class="notice">Er zijn nog geen meetpunten of metingen beschikbaar.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Apparaat</th>
                        <th>Meetpunt</th>
                        <th>Waarde</th>
                        <th>Eenheid</th>
                        <th>Bron tijd (UTC)</th>
                        <th>Verzameld (UTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($measurements as $measurement): ?>
                        <tr>
                            <td><?= nibe_escape($measurement['device_name']) ?></td>
                            <td><?= nibe_escape($measurement['name']) ?></td>
                            <td><?= $measurement['raw_value'] === null ? 'Geen meting' : nibe_escape($measurement['raw_value']) ?></td>
                            <td><?= nibe_escape($measurement['unit']) ?></td>
                            <td><?= nibe_escape($measurement['source_timestamp']) ?></td>
                            <td><?= nibe_escape($measurement['collected_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</body>
</html>
