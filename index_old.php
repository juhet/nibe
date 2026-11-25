<?php

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/dashboard.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();
$settingsCsrfToken = $_SESSION['settings_csrf_token'] ??= bin2hex(random_bytes(32));
$settingsMessage = $_SESSION['settings_log_message'] ?? null;
unset($_SESSION['settings_log_message']);
$settingsError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!hash_equals($settingsCsrfToken, $providedToken)) {
        http_response_code(400);
        $settingsError = 'De aanvraag kon niet worden gecontroleerd. Vernieuw de pagina en probeer opnieuw.';
    } else {
        try {
            $database = nibe_database();
            $action = $_POST['action'] ?? '';
            if ($action === 'save') {
                $entry = nibe_dashboard_setting_change_input($_POST);
                $id = $_POST['id'] ?? '';
                if ($id === '') {
                    $statement = $database->prepare(
                        'INSERT INTO nibe_setting_changes (changed_at, setting, old_value, new_value, note)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $statement->execute([
                        $entry['changed_at'], $entry['setting'], $entry['old_value'],
                        $entry['new_value'], $entry['note'],
                    ]);
                } elseif (is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
                    $statement = $database->prepare(
                        'UPDATE nibe_setting_changes
                         SET changed_at = ?, setting = ?, old_value = ?, new_value = ?, note = ?
                         WHERE id = ?'
                    );
                    $statement->execute([
                        $entry['changed_at'], $entry['setting'], $entry['old_value'],
                        $entry['new_value'], $entry['note'], $id,
                    ]);
                } else {
                    throw new InvalidArgumentException('De gekozen logboekregel is ongeldig.');
                }
                $_SESSION['settings_log_message'] = 'Instelling opgeslagen.';
            } elseif ($action === 'delete'
                && is_string($_POST['id'] ?? null)
                && preg_match('/^[1-9][0-9]{0,19}$/D', $_POST['id'])) {
                $statement = $database->prepare('DELETE FROM nibe_setting_changes WHERE id = ?');
                $statement->execute([$_POST['id']]);
                $_SESSION['settings_log_message'] = 'Instelling verwijderd.';
            } else {
                throw new InvalidArgumentException('De gekozen logboekactie is ongeldig.');
            }
            header('Location: index_old.php#settings-log', true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            $settingsError = $exception->getMessage();
        } catch (Throwable $exception) {
            $settingsError = 'De instelling kon niet worden opgeslagen. Controleer of de logboektabel is aangemaakt.';
        }
    }
}

function nibe_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nibe_debug(mixed $value, array $checks): string
{
    if ($value !== null) {
        return '';
    }

    $html = '<details class="debug"><summary>Toelichting</summary><ul>';
    foreach ($checks as $check) {
        $html .= '<li>' . ($check['ok'] ? '✓ ' : '✗ ') . nibe_escape($check['message']) . '</li>';
    }
    return $html . '</ul></details>';
}

$measurements = [];
$connections = [];
$settingChanges = [];
$defaultRoleMapping = [];
$historyByRole = [];
$performance = [];
$roleUnits = [];
$weather = null;
$databaseReady = true;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

try {
    $database = nibe_database();
    try {
        $weatherStatement = $database->prepare(
            'SELECT source_timestamp, raw_data
             FROM nibe_weather_observations
             ORDER BY source_timestamp DESC, id DESC
             LIMIT 1'
        );
        $weatherStatement->execute();
        $weather = nibe_dashboard_weather($weatherStatement->fetch() ?: null);
    } catch (Throwable) {
        $weather = null;
    }

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
    $defaultRoleMapping = nibe_dashboard_role_mapping(
        $measurements,
        nibe_env('NIBE_DASHBOARD_ROLE_MAP')
    );
    $measurementById = [];
    foreach ($measurements as $measurement) {
        $measurementById[(string) $measurement['measurement_point_id']] = $measurement;
    }
    foreach ($defaultRoleMapping as $role => $measurementId) {
        $roleUnits[$role] = (string) ($measurementById[$measurementId]['unit'] ?? '');
    }

    $historyRoles = ['frequency', 'supply', 'return', 'calculated_supply', 'hot_water', 'outdoor', 'degree_minutes',
        'electric_power', 'heat_power', 'auxiliary_heat_power', 'electric_energy', 'heat_energy', 'defrost'];
    $historyIds = [];
    foreach ($historyRoles as $role) {
        if (isset($defaultRoleMapping[$role])) {
            $historyIds[$role] = $defaultRoleMapping[$role];
        }
    }
    if ($historyIds !== []) {
        $uniqueHistoryIds = array_values(array_unique(array_values($historyIds)));
        $placeholders = implode(', ', array_fill(0, count($uniqueHistoryIds), '?'));
        $historyStart = $now->modify('-30 days')->format('Y-m-d H:i:s');
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
            ...$uniqueHistoryIds,
            $historyStart,
            $historyEnd,
            $historyStart,
            $historyEnd,
        ]);
        foreach ($historyStatement->fetchAll() as $observation) {
            foreach ($historyIds as $role => $measurementId) {
                if ((string) $observation['measurement_point_id'] === $measurementId) {
                    $historyByRole[$role][] = $observation;
                }
            }
        }

        $baselineStatement = $database->prepare(
            'SELECT raw_value, source_timestamp, collected_at
             FROM nibe_observations
             WHERE measurement_point_id = ?
               AND (source_timestamp < ? OR (source_timestamp IS NULL AND collected_at < ?))
             ORDER BY COALESCE(source_timestamp, collected_at) DESC, id DESC
             LIMIT 1'
        );
        foreach (['defrost', 'electric_energy', 'heat_energy', 'auxiliary_heat_power'] as $role) {
            if (!isset($historyIds[$role])) {
                continue;
            }
            $baselineStatement->execute([$historyIds[$role], $historyStart, $historyStart]);
            $baseline = $baselineStatement->fetch();
            if ($baseline !== false) {
                $historyByRole[$role][] = $baseline;
            }
        }
    }
    $performance = nibe_dashboard_performance_metrics($historyByRole, $roleUnits, $now);

    $connectionStatement = $database->prepare(
        'SELECT source, authorization_status, last_success_at
         FROM nibe_connections
         ORDER BY source
         LIMIT 20'
    );
    $connectionStatement->execute();
    $connections = $connectionStatement->fetchAll();

    $settingStatement = $database->prepare(
        'SELECT id, changed_at, setting, old_value, new_value, note
         FROM nibe_setting_changes
         ORDER BY changed_at DESC, id DESC
         LIMIT 500'
    );
    $settingStatement->execute();
    $settingChanges = $settingStatement->fetchAll();
} catch (Throwable $exception) {
    $databaseReady = false;
}

$installationLabel = nibe_env('NIBE_INSTALLATION_LABEL', 'NIBE-installatie');
$performance += [
    'periods' => [],
    'cop_now' => null,
    'cop_now_timestamp' => null,
    'cop_warning' => false,
    'delta_t_now' => null,
    'compressor_starts' => null,
    'compressor_starts_7d' => null,
    'compressor_periods' => [],
    'compressor_comparisons' => [],
    'cycles' => [],
    'average_run_seconds' => null,
    'longest_run_seconds' => null,
    'shortest_run_seconds' => null,
    'frequency_distribution' => [],
    'frequency_seconds' => [],
    'cop_by_outdoor_temperature' => [],
    'data_coverage' => [],
    'defrost_periods' => [],
    'auxiliary_heat_power_now' => null,
    'auxiliary_heat_power_timestamp' => null,
    'auxiliary_heat_periods' => [],
];
$suggestions = nibe_dashboard_suggestions($performance);
$selectedCyclePeriod = $_GET['cycle_period'] ?? '24h';
if (!is_string($selectedCyclePeriod) || !in_array($selectedCyclePeriod, ['24h', '7d', '30d'], true)) {
    $selectedCyclePeriod = '24h';
}
$selectedDefrostPeriod = $_GET['defrost_period'] ?? '24h';
if (!is_string($selectedDefrostPeriod)
    || !in_array($selectedDefrostPeriod, ['24h', '7d', '30d'], true)) {
    $selectedDefrostPeriod = '24h';
}
$selectedDefrostLabel = ['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'][$selectedDefrostPeriod];
$currentHeatPower = null;
$heatPowerDebug = [];
$heatPowerMapped = isset($defaultRoleMapping['heat_power'], $measurementById[$defaultRoleMapping['heat_power']]);
$heatPowerDebug[] = ['ok' => $heatPowerMapped, 'message' => 'Warmtevermogen gekoppeld'];
if ($heatPowerMapped) {
    $heatPowerMeasurement = $measurementById[$defaultRoleMapping['heat_power']];
    $freshness = $heatPowerMeasurement['source_timestamp'] ?? $heatPowerMeasurement['collected_at'];
    $numeric = is_string($heatPowerMeasurement['raw_value']) && is_numeric($heatPowerMeasurement['raw_value']);
    $heatPowerDebug[] = ['ok' => $numeric, 'message' => 'Laatste verzamelde warmtevermogen is een numerieke string'];
    $heatPowerDebug[] = ['ok' => is_string($freshness), 'message' => 'Bron- of verzameltijd gevonden'];
    if (is_string($freshness)) {
        try {
            $freshnessTime = (new DateTimeImmutable($freshness, new DateTimeZone('UTC')))->getTimestamp();
            $age = $now->getTimestamp() - $freshnessTime;
            $fresh = $age >= 0 && $age <= 900;
            $heatPowerDebug[] = ['ok' => $fresh, 'message' => 'Meting is ' . $age . ' seconden oud (tussen 0 en 900)'];
            if ($numeric && $fresh) {
                $currentHeatPower = $heatPowerMeasurement['raw_value'] . ' ' . ($roleUnits['heat_power'] ?? '');
            }
        } catch (Exception $exception) {
            $heatPowerDebug[] = ['ok' => false, 'message' => 'Bron- of verzameltijd is ongeldig'];
            $currentHeatPower = null;
        }
    }
}
$importantRoles = [
    'indoor' => 'Temperatuur in huis',
    'hot_water' => 'Temperatuur warm tapwater',
    'outdoor' => 'Buitentemperatuur',
    'evaporator' => 'Temperatuur verdamper',
    'degree_minutes' => 'Graadminuten',
    'frequency' => 'Compressorfrequentie',
    'calculated_supply' => 'Berekende toevoer',
    'supply' => 'Aanvoer',
    'return' => 'Retour',
    'heat_power' => 'Warmtevermogen',
    'electric_power' => 'Elektrisch vermogen',
];
$chartRoleOrder = ['hot_water', 'calculated_supply', 'supply', 'return', 'indoor', 'outdoor', 'evaporator',
    'degree_minutes', 'frequency'];
$chartPointIds = array_values(array_filter(array_map(
    static fn (string $role): ?string => $defaultRoleMapping[$role] ?? null,
    $chartRoleOrder
)));
if ($nibeDashboardDataOnly ?? false) {
    return;
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= nibe_escape($installationLabel) ?> — dashboard technisch</title>
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
        .debug { margin-top: .6rem; font-size: .85rem; font-weight: normal; overflow-wrap: anywhere; }
        .debug summary { cursor: pointer; }
        .debug ul { padding: 0; margin: .25rem 0 0; list-style: none; }
        .important-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: .5rem 1rem; }
        .important-item { min-width: 0; padding: .55rem .7rem; background: #f3f6f8; border-radius: .4rem; }
        .important-item h3 { margin: 0; font-size: .9rem; }
        .important-item .value { margin: .15rem 0; font-size: 1.25rem; }
        .important-chart { margin-top: .5rem; }
        .important-chart .chart { min-height: 0; }
        .suggestions { margin-top: 1rem; padding: .75rem; background: #f3f6f8; border-radius: .5rem; }
        .suggestions h3 { margin: 0; }
        .suggestions ul { display: grid; gap: .75rem; margin-bottom: 0; padding-left: 1.25rem; }
        .suggestions p { margin: .25rem 0 0; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: .5rem 1rem; margin-top: 1rem; }
        .kpi { padding: .65rem .75rem; background: #eaf2f4; border-radius: .4rem; }
        .kpi strong { display: block; font-size: 1.2rem; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: .5rem 1rem; }
        .metric { padding: .7rem; background: #f3f6f8; border-radius: .4rem; }
        .metric strong { display: block; font-size: 1.1rem; }
        .warning { padding: .65rem; color: #8a4b00; background: #fff3cd; border-radius: .4rem; }
        .bands { display: grid; grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr)); gap: .5rem; }
        .frequency-bar { display: flex; height: .9rem; margin: .75rem 0; overflow: hidden; border-radius: .45rem; background: #e5ebef; }
        .frequency-bar span { height: 100%; }
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
        .settings-table-wrap { overflow-x: auto; }
        .degree-day-trend { display: block; width: 100%; max-width: 640px; height: auto; }
        .settings-table { width: 100%; border-collapse: collapse; }
        .settings-table th, .settings-table td { padding: .6rem; border-bottom: 1px solid #d8e0e6; text-align: left; vertical-align: top; }
        .settings-actions { display: flex; flex-wrap: wrap; gap: .4rem; }
        dialog { width: min(38rem, calc(100% - 2rem)); max-height: 90vh; overflow-y: auto; border: 0; border-radius: .6rem; box-shadow: 0 1rem 3rem #17212b55; }
        dialog::backdrop { background: #17212b77; }
        .settings-form { display: grid; gap: .75rem; }
        .settings-form label { display: grid; gap: .25rem; }
        .settings-form textarea { min-height: 5rem; resize: vertical; }
        .settings-actions-row { display: flex; flex-wrap: wrap; gap: .5rem; }
        summary { cursor: pointer; font-weight: 650; }
    </style>
</head>
<body>
    <p><a href="index.php">← Terug naar overzicht</a></p>
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
                            : '<time class="local-time" datetime="' . nibe_escape($connection['last_success_at']) . '">Tijd laden…</time>' ?>
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
                        $freshness = nibe_dashboard_measurement_freshness(
                            $freshnessTimestamp,
                            $now
                        );
                        $isEmpty = $measurement['raw_value'] === null || $freshness['status'] === 'missing';
                        $isStale = !$isEmpty && $freshness['status'] === 'stale';
                        $statusClass = $isEmpty ? '' : ($isStale ? ' stale' : ' live');
                        $age = $freshness['age_seconds'];
                        $ageLabel = $age === null ? '' : ($age < 0
                            ? ' · ongeldige tijd'
                            : ($age < 60
                            ? ' · zojuist'
                            : ($age < 3600
                                ? ' · ' . floor($age / 60) . ' min geleden'
                                : ' · ' . floor($age / 3600) . ' uur geleden')));
                        $statusLabel = $isEmpty ? 'Ontbrekend' : ($isStale ? 'Verouderd' : 'Live') . $ageLabel;
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
                            <?php if (!$isEmpty && is_string($freshnessTimestamp)): ?>
                                <small>Laatste meting: <time class="local-time" datetime="<?= nibe_escape($freshnessTimestamp) ?>">Tijd laden…</time></small>
                            <?php endif; ?>
                            <button type="button" class="important-chart-toggle"
                                    data-point-id="<?= nibe_escape($defaultRoleMapping[$role]) ?>"
                                    aria-controls="important-chart-<?= nibe_escape($role) ?>"
                                    aria-expanded="false">Toon 24-uursgrafiek</button>
                            <div class="important-chart" id="important-chart-<?= nibe_escape($role) ?>" hidden></div>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <section class="suggestions" aria-labelledby="suggestions-title" id="optimization-suggestions">
                <h3 id="suggestions-title">Suggesties op basis van meetdata</h3>
                <?php if ($suggestions === []): ?>
                    <p class="muted">Geen onderbouwde suggesties op basis van de beschikbare meetdata. Dit betekent niet automatisch dat alle instellingen optimaal zijn.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($suggestions as $suggestion): ?>
                            <li>
                                <strong><?= nibe_escape($suggestion['title']) ?></strong>
                                <p><?= nibe_escape($suggestion['evidence']) ?></p>
                                <p><?= nibe_escape($suggestion['action']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted">Dit zijn aandachtspunten, geen automatische instellingenadviezen. Beoordeel eerst de bedrijfsomstandigheden en verander instellingen één voor één.</p>
                <?php endif; ?>
            </section>
            <p class="muted">Compressor en warmteafgifte — afgelopen 24 uur</p>
            <div class="kpis">
                <div class="kpi">Compressorstarts afgelopen 24 uur<strong><?= $performance['compressor_starts'] === null ? 'Niet beschikbaar' : nibe_escape((string) $performance['compressor_starts']) ?></strong><?= nibe_debug($performance['compressor_starts'], $performance['debug']['frequency']['24h']) ?></div>
                <div class="kpi">Compressorstarts afgelopen 7 dagen<strong><?= $performance['compressor_starts_7d'] === null ? 'Niet beschikbaar' : nibe_escape((string) $performance['compressor_starts_7d']) ?></strong><?= nibe_debug($performance['compressor_starts_7d'], $performance['debug']['frequency']['7d']) ?></div>
                <div class="kpi">Gemiddelde runduur<strong><?= $performance['average_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['average_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($performance['average_run_seconds'], $performance['debug']['runtime']) ?></div>
                <div class="kpi">Langste runduur<strong><?= $performance['longest_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['longest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($performance['longest_run_seconds'], $performance['debug']['runtime']) ?></div>
                <div class="kpi">Kortste runduur<strong><?= $performance['shortest_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['shortest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($performance['shortest_run_seconds'], $performance['debug']['runtime']) ?></div>
                <div class="kpi">ΔT nu<strong><?= $performance['delta_t_now'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['delta_t_now'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong><?= nibe_debug($performance['delta_t_now'], $performance['debug']['delta_t_now']) ?></div>
                <div class="kpi">Gemiddelde ΔT<strong><?= $performance['periods']['24h']['average_delta_t'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['24h']['average_delta_t'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong><?= nibe_debug($performance['periods']['24h']['average_delta_t'], $performance['debug']['periods']['24h']['average_delta_t']) ?></div>
                <div class="kpi">Gemiddelde ΔT afgelopen 7 dagen<strong><?= $performance['periods']['7d']['average_delta_t'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['7d']['average_delta_t'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong><?= nibe_debug($performance['periods']['7d']['average_delta_t'], $performance['debug']['periods']['7d']['average_delta_t']) ?></div>
                <div class="kpi">Warmtevermogen nu<strong><?= $currentHeatPower === null ? 'Niet beschikbaar' : nibe_escape($currentHeatPower) ?></strong><?= nibe_debug($currentHeatPower, $heatPowerDebug) ?></div>
            </div>
            <h3>Compressorcycli — afgelopen <?= $selectedCyclePeriod === '24h' ? '24 uur' : ($selectedCyclePeriod === '7d' ? '7 dagen' : '30 dagen') ?></h3>
            <form method="get" class="controls">
                <label>Periode compressorcycli
                    <select name="cycle_period">
                        <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                            <option value="<?= nibe_escape($period) ?>"<?= $selectedCyclePeriod === $period ? ' selected' : '' ?>><?= nibe_escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Toon periode</button>
            </form>
            <?php
            $cycleSummary = $performance['compressor_periods'][$selectedCyclePeriod] ?? null;
            $cycleCutoff = $now->getTimestamp() - match ($selectedCyclePeriod) {
                '7d' => 7 * 86400,
                '30d' => 30 * 86400,
                default => 86400,
            };
            $periodCycles = array_values(array_filter(
                $performance['cycles'],
                static fn (array $cycle): bool => !$cycle['is_complete'] || $cycle['start'] >= $cycleCutoff
            ));
            $periodCycles = array_slice(array_reverse($periodCycles), 0, 100);
            ?>
            <?php if ($cycleSummary !== null): ?>
                <div class="metric-grid">
                    <div class="metric">Compressorstarts<strong><?= $cycleSummary['starts'] === null ? 'Onvolledig' : nibe_escape((string) $cycleSummary['starts']) ?></strong></div>
                    <div class="metric">Totale draaitijd<strong><?= $cycleSummary['total_runtime_seconds'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['total_runtime_seconds'] / 3600, 1, ',', '') . ' uur') ?></strong></div>
                    <div class="metric">Gemiddelde runduur<strong><?= $cycleSummary['average_run_seconds'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['average_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                    <div class="metric">Langste runduur<strong><?= $cycleSummary['longest_run_seconds'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['longest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                    <div class="metric">Kortste runduur<strong><?= $cycleSummary['shortest_run_seconds'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['shortest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                    <div class="metric">Runs korter dan 30 min<strong><?= $cycleSummary['runs_under_30_minutes'] === null ? 'Onvolledig' : nibe_escape((string) $cycleSummary['runs_under_30_minutes']) ?></strong></div>
                    <div class="metric">Runs korter dan 60 min<strong><?= $cycleSummary['runs_under_60_minutes'] === null ? 'Onvolledig' : nibe_escape((string) $cycleSummary['runs_under_60_minutes']) ?></strong></div>
                    <div class="metric">Gemiddelde tijd tussen starts<strong><?= $cycleSummary['average_interval_seconds'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['average_interval_seconds'] / 3600, 1, ',', '') . ' uur') ?></strong></div>
                    <div class="metric">Gemiddelde ΔT<strong><?= $cycleSummary['average_delta_t'] === null ? 'Onvolledig' : nibe_escape(number_format($cycleSummary['average_delta_t'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong></div>
                    <div class="metric">Waargenomen datadekking<strong><?= nibe_escape(number_format($cycleSummary['coverage_percentage'], 1, ',', '') . '%') ?></strong></div>
                </div>
                <?php if (!$cycleSummary['complete']): ?>
                    <p class="warning">Deze periode is onvolledig. Runs en starts worden alleen getoond wanneer de frequentiehistorie voldoende recent is; gaten langer dan 60 minuten worden niet als een compressorstop geïnterpreteerd.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (isset($performance['compressor_comparisons'][$selectedCyclePeriod])): ?>
                <?php
                $cycleComparison = $performance['compressor_comparisons'][$selectedCyclePeriod];
                $comparisonLabels = [
                    'starts' => 'Compressorstarts',
                    'total_runtime_seconds' => 'Totale draaitijd',
                    'average_run_seconds' => 'Gemiddelde runduur',
                    'runs_under_30_minutes' => 'Runs korter dan 30 min',
                ];
                $comparisonFormat = static function (?float $value, string $metric): string {
                    if ($value === null) {
                        return 'Onvolledig';
                    }
                    return match ($metric) {
                        'total_runtime_seconds' => number_format($value / 3600, 1, ',', '') . ' uur',
                        'average_run_seconds' => number_format($value / 60, 1, ',', '') . ' min',
                        default => number_format($value, 1, ',', ''),
                    };
                };
                ?>
                <h4>Vergelijking met de vorige <?= $selectedCyclePeriod === '24h' ? '24 uur' : '7 dagen' ?></h4>
                <p class="muted">Verschillen zijn rekenkundig (huidige periode min vorige periode), zonder beoordeling of minder/meerder starts beter is.</p>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Meting</th><th>Huidige periode</th><th>Vorige periode</th><th>Verschil</th></tr></thead>
                        <tbody>
                            <?php foreach ($comparisonLabels as $metric => $label): ?>
                                <?php
                                $currentValue = $cycleSummary[$metric] ?? null;
                                $previousValue = $cycleComparison['previous'][$metric] ?? null;
                                $change = $cycleComparison['changes'][$metric] ?? null;
                                ?>
                                <tr>
                                    <th scope="row"><?= nibe_escape($label) ?></th>
                                    <td><?= nibe_escape($comparisonFormat($currentValue === null ? null : (float) $currentValue, $metric)) ?></td>
                                    <td><?= nibe_escape($comparisonFormat($previousValue === null ? null : (float) $previousValue, $metric)) ?></td>
                                    <td><?= nibe_escape($comparisonFormat($change === null ? null : (float) $change, $metric)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted">Datadekking: huidige <?= nibe_escape(number_format($cycleSummary['coverage_percentage'], 1, ',', '') . '%') ?>; vorige <?= nibe_escape(number_format($cycleComparison['previous']['coverage_percentage'], 1, ',', '') . '%') ?>. Onvolledige perioden worden niet met getallen vergeleken.</p>
            <?php endif; ?>
            <h4>Recente individuele cycli</h4>
            <p class="muted">Een actieve cyclus heeft als voorlopig einde de huidige tijd. De type-indicatie gebruikt het teken van ΔT voor verwarming of koeling en een stijging van minimaal 1 °C in tapwatertemperatuur voor warm water.</p>
            <?php if ($periodCycles === []): ?>
                <p class="muted">Geen volledig waargenomen compressorcycli in deze periode.</p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Start</th><th>Einde</th><th>Runduur</th><th>COP</th><th>Type (indicatie)</th><th>Details</th></tr></thead>
                        <tbody>
                            <?php foreach ($periodCycles as $cycle): ?>
                                <tr>
                                    <td><time class="local-time" datetime="<?= nibe_escape(gmdate('Y-m-d H:i:s', $cycle['start'])) ?>">Tijd laden…</time></td>
                                    <td><time class="local-time" datetime="<?= nibe_escape(gmdate('Y-m-d H:i:s', $cycle['end'])) ?>">Tijd laden…</time><?= $cycle['is_complete'] ? '' : ' (actief)' ?></td>
                                    <td><?= nibe_escape(number_format($cycle['duration_seconds'] / 60, 1, ',', '') . ' min') ?></td>
                                    <td><?= $cycle['cop'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['cop'], 2, ',', '')) ?></td>
                                    <td><?= $cycle['operation'] === null ? 'Onbekend' : nibe_escape($cycle['operation']) ?></td>
                                    <td>
                                        <details>
                                            <summary>Meetwaarden</summary>
                                            <ul>
                                                <li>Graadminuten start/einde: <?= $cycle['degree_minutes_start'] === null ? 'Niet beschikbaar' : nibe_escape((string) $cycle['degree_minutes_start']) ?> / <?= $cycle['degree_minutes_end'] === null ? 'Niet beschikbaar' : nibe_escape((string) $cycle['degree_minutes_end']) ?></li>
                                                <li>Graadminuten min/max: <?= $cycle['degree_minutes_min'] === null ? 'Niet beschikbaar' : nibe_escape((string) $cycle['degree_minutes_min']) ?> / <?= $cycle['degree_minutes_max'] === null ? 'Niet beschikbaar' : nibe_escape((string) $cycle['degree_minutes_max']) ?></li>
                                                <li>Compressorfrequentie max/gemiddeld: <?= $cycle['maximum_frequency'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['maximum_frequency'], 1, ',', '') . ' Hz') ?> / <?= $cycle['average_frequency'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['average_frequency'], 1, ',', '') . ' Hz') ?></li>
                                                <li>Gemiddelde aanvoer/retour: <?= $cycle['average_supply'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['average_supply'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?> / <?= $cycle['average_return'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['average_return'], 1, ',', '') . ' ' . ($roleUnits['return'] ?? '')) ?></li>
                                                <li>Gemiddelde ΔT: <?= $cycle['average_delta_t'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['average_delta_t'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></li>
                                                <li>Gemiddelde buitentemperatuur: <?= $cycle['average_outdoor'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($cycle['average_outdoor'], 1, ',', '') . ' ' . ($roleUnits['outdoor'] ?? '')) ?></li>
                                                <li>Elektriciteitsverbruik: <?= $cycle['electricity_kwh'] === null ? 'Niet betrouwbaar beschikbaar' : nibe_escape(number_format($cycle['electricity_kwh'], 3, ',', '') . ' kWh') ?></li>
                                                <li>Warmteproductie: <?= $cycle['heat_kwh'] === null ? 'Niet betrouwbaar beschikbaar' : nibe_escape(number_format($cycle['heat_kwh'], 3, ',', '') . ' kWh') ?></li>
                                            </ul>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($periodCycles) === 100): ?><p class="muted">De lijst toont maximaal de 100 recentste cycli.</p><?php endif; ?>
            <?php endif; ?>
            <p class="muted">ΔT nu vereist aanvoer en retour met dezelfde eenheid, maximaal 5 minuten tussen de brontijden en een recentste meting van maximaal 15 minuten oud. De live-status vereist een meting van maximaal 15 minuten oud.</p>
        </section>
        <section class="panel" aria-labelledby="performance-title">
            <h2 id="performance-title">Prestatie-inzichten</h2>
            <h3>Efficiëntie (COP) en energie</h3>
            <div class="metric-grid" aria-label="COP">
                <div class="metric">COP nu<strong><?= $performance['cop_now'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['cop_now'], 2, ',', '')) ?></strong>
                    <?= nibe_debug($performance['cop_now'], $performance['debug']['cop_now']) ?>
                    <?php if ($performance['cop_now_timestamp'] !== null): ?>
                        <small>Meetmoment: <time class="local-time" datetime="<?= nibe_escape($performance['cop_now_timestamp']) ?>">Tijd laden…</time></small>
                    <?php endif; ?>
                </div>
                <div class="metric">COP sinds <time class="local-time" datetime="<?= $now->format('Y-m-d 00:00:00') ?>">tijd laden…</time><strong><?= ($performance['periods']['today']['cop'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['cop'], 2, ',', '')) ?></strong><?= nibe_debug($performance['periods']['today']['cop'] ?? null, $performance['debug']['periods']['today']['cop']) ?></div>
                <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                    <div class="metric">COP <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['cop'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['cop'], 2, ',', '')) ?></strong><?= nibe_debug($performance['periods'][$period]['cop'] ?? null, $performance['debug']['periods'][$period]['cop']) ?></div>
                <?php endforeach; ?>
            </div>
            <?php if ($performance['cop_warning']): ?>
                <p class="warning" role="status">COP nu wijkt meer dan 20% af van het gemiddelde van de afgelopen 24 uur.</p>
            <?php endif; ?>
            <div class="kpis">
                <div class="kpi">Elektriciteit sinds <time class="local-time" datetime="<?= $now->format('Y-m-d 00:00:00') ?>">tijd laden…</time><strong><?= ($performance['periods']['today']['electricity'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['electricity'], 2, ',', '') . ' kWh') ?></strong><?= nibe_debug($performance['periods']['today']['electricity'] ?? null, $performance['debug']['periods']['today']['electricity']) ?></div>
                <div class="kpi">Warmte sinds <time class="local-time" datetime="<?= $now->format('Y-m-d 00:00:00') ?>">tijd laden…</time><strong><?= ($performance['periods']['today']['heat'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['heat'], 2, ',', '') . ' kWh') ?></strong><?= nibe_debug($performance['periods']['today']['heat'] ?? null, $performance['debug']['periods']['today']['heat']) ?></div>
                <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                    <div class="kpi">Elektriciteit <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['electricity'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['electricity'], 2, ',', '') . ' kWh') ?></strong><?= nibe_debug($performance['periods'][$period]['electricity'] ?? null, $performance['debug']['periods'][$period]['electricity']) ?></div>
                    <div class="kpi">Warmte <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['heat'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['heat'], 2, ',', '') . ' kWh') ?></strong><?= nibe_debug($performance['periods'][$period]['heat'] ?? null, $performance['debug']['periods'][$period]['heat']) ?></div>
                <?php endforeach; ?>
            </div>
            <details class="coverage">
                <summary>Datadekking en werkelijke meetperiode</summary>
                <ul>
                    <?php foreach (['today' => 'huidige periode', '24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                        <?php foreach (['electricity' => 'Elektriciteit', 'heat' => 'Warmte'] as $metric => $metricLabel): ?>
                            <?php $coverage = $performance['data_coverage'][$period][$metric] ?? null; ?>
                            <li>
                                <?= nibe_escape($metricLabel . ' ' . $label) ?>:
                                <?= $coverage === null ? 'Geen gegevens' : nibe_escape((string) $coverage['count']) . ' meetpunten' ?>
                                <?php if ($coverage !== null && $coverage['coverage_percentage'] !== null): ?>
                                    · <?= nibe_escape(number_format($coverage['coverage_percentage'], 1, ',', '') . '% geschatte meetdekking, op basis van 5 minuten per opgeslagen vermogensmeting') ?>
                                <?php endif; ?>
                                <?php if ($coverage !== null && $coverage['first_timestamp'] !== null): ?>
                                    · <time class="local-time" datetime="<?= nibe_escape($coverage['first_timestamp']) ?>">Tijd laden…</time>
                                    – <time class="local-time" datetime="<?= nibe_escape($coverage['last_timestamp']) ?>">Tijd laden…</time>
                                <?php else: ?> · geen metingen in periode<?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </details>
            <h3>Warmtevraag en weersgenormaliseerd verbruik</h3>
            <?php $heatingAnalysis = $performance['heating_analysis'] ?? ['periods' => [], 'daily' => []]; ?>
            <div class="settings-table-wrap">
                <table class="settings-table">
                    <thead><tr><th>Periode</th><th>Warmteverlies</th><th>Warmtevraag bij 0 °C</th><th>Elektriciteit / graaddag</th><th>Warmte / graaddag</th><th>Gem. COP</th><th>Indicatie</th></tr></thead>
                    <tbody>
                        <?php foreach (['24h' => 'Laatste 24 uur', '7d' => 'Laatste 7 dagen', '30d' => 'Laatste 30 dagen'] as $period => $label): ?>
                            <?php $analysis = $heatingAnalysis['periods'][$period] ?? []; ?>
                            <tr>
                                <th scope="row"><?= nibe_escape($label) ?></th>
                                <td><?= ($analysis['heat_loss_w_per_k'] ?? null) === null ? 'Onvoldoende gegevens' : nibe_escape(number_format($analysis['heat_loss_w_per_k'], 0, ',', '') . ' W/K') ?></td>
                                <td><?= ($analysis['heat_demand_at_zero_kw'] ?? null) === null ? 'Onvoldoende gegevens' : nibe_escape(number_format($analysis['heat_demand_at_zero_kw'], 1, ',', '') . ' kW') ?></td>
                                <td><?= ($analysis['electricity_per_degree_day'] ?? null) === null ? 'Onvoldoende gegevens' : nibe_escape(number_format($analysis['electricity_per_degree_day'], 1, ',', '') . ' kWh/graaddag') ?></td>
                                <td><?= ($analysis['heat_per_degree_day'] ?? null) === null ? 'Onvoldoende gegevens' : nibe_escape(number_format($analysis['heat_per_degree_day'], 1, ',', '') . ' kWh/graaddag') ?></td>
                                <td><?= ($analysis['average_cop'] ?? null) === null ? '—' : nibe_escape(number_format($analysis['average_cop'], 2, ',', '')) ?></td>
                                <td><?= nibe_escape(($analysis['benchmark'] ?? null) ?? 'Onvoldoende gegevens') ?> · <?= nibe_escape($analysis['reliability'] ?? 'Onvoldoende gegevens') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted">Graaddagen gebruiken een vaste binnentemperatuurbasis van 20 °C: per interval telt alleen max(0, 20 °C − buitentemperatuur) mee. Energie en graaddagen tellen alleen mee voor gemeten intervallen met warmte-, elektriciteits- en buitentemperatuurmetingen die maximaal 5 minuten bij de intervalgrenzen liggen; intervallen langer dan 15 minuten worden overgeslagen. Warmteverlies en warmtevraag zijn indicatief, geen bouwkundige meting. De prestatie-indicatie is geen woningvergelijking: woningtype, grootte, ventilatie en bewonersgedrag hebben grote invloed.</p>
            <h4>Elektriciteit per graaddag — trend laatste 30 dagen</h4>
            <?php
            $trendValues = array_values(array_filter(
                $heatingAnalysis['daily'] ?? [],
                static fn (array $day): bool => ($day['electricity_per_degree_day'] ?? null) !== null
            ));
            $trendPoints = [];
            if (count($trendValues) >= 2) {
                $trendNumbers = array_column($trendValues, 'electricity_per_degree_day');
                $trendMin = min($trendNumbers);
                $trendMax = max($trendNumbers);
                foreach ($trendValues as $index => $day) {
                    $x = 30 + 580 * $index / (count($trendValues) - 1);
                    $y = 150 - 120 * (($day['electricity_per_degree_day'] - $trendMin) / max(0.01, $trendMax - $trendMin));
                    $trendPoints[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
                }
            }
            ?>
            <?php if (count($trendValues) >= 2): ?>
                <svg class="degree-day-trend" role="img" aria-label="Elektriciteitsverbruik per graaddag door de tijd" viewBox="0 0 640 180">
                    <line x1="30" y1="150" x2="610" y2="150" stroke="currentColor" opacity=".3"></line>
                    <polyline points="<?= nibe_escape(implode(' ', $trendPoints)) ?>" fill="none" stroke="currentColor" stroke-width="3"></polyline>
                    <?php foreach ($trendValues as $index => $day): ?>
                        <?php [$x, $y] = explode(',', $trendPoints[$index]); ?>
                        <circle cx="<?= nibe_escape($x) ?>" cy="<?= nibe_escape($y) ?>" r="3" fill="currentColor">
                            <title><?= nibe_escape($day['date'] ?? '') ?>: <?= nibe_escape(number_format($day['electricity_per_degree_day'], 1, ',', '')) ?> kWh/graaddag</title>
                        </circle>
                    <?php endforeach; ?>
                </svg>
                <p class="muted">Elke punt is een dag op basis van gemeten intervallen; dagen zonder bruikbare graaddagen worden niet getoond.</p>
            <?php else: ?>
                <p class="muted">Nog onvoldoende gemeten dagen voor een trendgrafiek.</p>
            <?php endif; ?>
            <h3>Elektrische bijverwarming</h3>
            <div class="metric-grid">
                <div class="metric">Bijverwarmingsvermogen nu<strong><?= $performance['auxiliary_heat_power_now'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['auxiliary_heat_power_now'], 2, ',', '') . ' kW') ?></strong>
                    <?= nibe_debug($performance['auxiliary_heat_power_now'], $performance['debug']['auxiliary_heat']['power_now'] ?? []) ?>
                    <?php if ($performance['auxiliary_heat_power_timestamp'] !== null): ?>
                        <small>Meetmoment: <time class="local-time" datetime="<?= nibe_escape($performance['auxiliary_heat_power_timestamp']) ?>">Tijd laden…</time></small>
                    <?php endif; ?>
                </div>
                <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                    <?php $auxiliaryHeat = $performance['auxiliary_heat_periods'][$period] ?? []; ?>
                    <div class="metric">Waargenomen starts bijverwarming <?= nibe_escape($label) ?><strong><?= ($auxiliaryHeat['starts'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape((string) $auxiliaryHeat['starts']) ?></strong>
                        <?= nibe_debug($auxiliaryHeat['starts'] ?? null, $performance['debug']['auxiliary_heat']['periods'][$period]['starts'] ?? []) ?>
                    </div>
                    <div class="metric">Elektriciteit bijverwarming <?= nibe_escape($label) ?><strong><?= ($auxiliaryHeat['electricity_kwh'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($auxiliaryHeat['electricity_kwh'], 2, ',', '') . ' kWh') ?></strong>
                        <?= nibe_debug($auxiliaryHeat['electricity_kwh'] ?? null, $performance['debug']['auxiliary_heat']['periods'][$period]['electricity_kwh'] ?? []) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted">Starts zijn waargenomen overgangen van 0 naar positief vermogen (tussen opeenvolgende metingen maximaal één uur uit elkaar). Verbruik schat vijf minuten per opgeslagen vermogensmeting; ontbrekende intervallen worden niet aangevuld.</p>
            <h3>COP naar buitentemperatuur — beschikbare historie (maximaal 30 dagen)</h3>
            <div class="settings-table-wrap">
                <table class="settings-table">
                    <thead><tr><th>Buitentemperatuur</th><th>Metingen / dekking</th><th>Gem. buiten</th><th>Elektriciteit</th><th>Warmte</th><th>COP</th><th>Werkelijke meetperiode</th></tr></thead>
                    <tbody>
                        <?php foreach ($performance['cop_by_outdoor_temperature'] as $label => $bucket): ?>
                            <tr>
                                <th scope="row"><?= nibe_escape($label) ?></th>
                                <td><?= nibe_escape((string) ($bucket['count'] ?? 0)) ?> metingen · <?= nibe_escape(number_format($bucket['coverage_minutes'] ?? 0, 0, ',', '')) ?> min gemeten</td>
                                <td><?= ($bucket['average_outdoor'] ?? null) === null ? '—' : nibe_escape(number_format($bucket['average_outdoor'], 1, ',', '') . ' °C') ?></td>
                                <td><?= !($bucket['available'] ?? false) ? 'Te weinig betrouwbare data' : nibe_escape(number_format($bucket['electricity'], 2, ',', '') . ' kWh') ?></td>
                                <td><?= !($bucket['available'] ?? false) ? 'Te weinig betrouwbare data' : nibe_escape(number_format($bucket['heat'], 2, ',', '') . ' kWh') ?></td>
                                <td><?= ($bucket['cop'] ?? null) === null ? 'Te weinig betrouwbare data' : nibe_escape(number_format($bucket['cop'], 2, ',', '')) ?></td>
                                <td>
                                    <?php if (($bucket['first_time'] ?? null) !== null): ?>
                                        <time class="local-time" datetime="<?= nibe_escape(gmdate('Y-m-d H:i:s', $bucket['first_time'])) ?>">Tijd laden…</time>
                                        –
                                        <time class="local-time" datetime="<?= nibe_escape(gmdate('Y-m-d H:i:s', $bucket['last_time'])) ?>">Tijd laden…</time>
                                    <?php else: ?>Geen gekoppelde metingen<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted">Alleen daadwerkelijk gemeten buitentemperatuur, elektrisch vermogen en warmtevermogen die maximaal 5 minuten uit elkaar liggen worden gekoppeld. Energie wordt uitsluitend over opeenvolgende meetparen met maximaal 15 minuten tussenruimte berekend; COP wordt pas getoond vanaf 3 meetparen en 10 minuten dekking. Ontbrekende intervallen worden niet aangevuld.</p>
            <h3>Compressorfrequentie — afgelopen 24 uur</h3>
            <div class="bands">
                <?php foreach (['0' => '0 Hz', '1-20' => '1–20 Hz', '20-30' => '20–30 Hz', '30-40' => '30–40 Hz', '40-50' => '40–50 Hz', '50-60' => '50–60 Hz', '60+' => '60 Hz of hoger'] as $band => $label): ?>
                    <div class="metric"><?= nibe_escape($label) ?><strong><?= ($performance['frequency_distribution'][$band] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['frequency_distribution'][$band], 1, ',', '') . '% · ' . number_format(($performance['frequency_seconds'][$band] ?? 0) / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($performance['frequency_distribution'][$band] ?? null, $performance['debug']['frequency_distribution']) ?></div>
                <?php endforeach; ?>
            </div>
            <?php if (($performance['frequency_distribution']['0'] ?? null) !== null): ?>
                <?php
                $frequencyColors = ['#9aa8b2', '#84b9a1', '#5cae87', '#468fa1', '#3871a3', '#6657a5', '#884b83'];
                $frequencyBandNames = ['0' => '0 Hz', '1-20' => '1–20 Hz', '20-30' => '20–30 Hz',
                    '30-40' => '30–40 Hz', '40-50' => '40–50 Hz', '50-60' => '50–60 Hz', '60+' => '60 Hz of hoger'];
                ?>
                <div class="frequency-bar" role="img" aria-label="Compressorfrequentieverdeling afgelopen 24 uur">
                    <?php foreach ($frequencyBandNames as $colorIndex => $label): ?>
                        <?php $band = array_keys($frequencyBandNames)[$colorIndex]; ?>
                        <?php $percentage = $performance['frequency_distribution'][$band] ?? 0; ?>
                        <span style="width: <?= nibe_escape(number_format($percentage, 3, '.', '')) ?>%; background: <?= nibe_escape($frequencyColors[$colorIndex]) ?>" title="<?= nibe_escape($label . ': ' . number_format($percentage, 1, ',', '') . '%') ?>"></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="get" class="controls">
                <label>Periode ontdooien
                    <select name="defrost_period">
                        <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                            <option value="<?= nibe_escape($period) ?>"<?= $selectedDefrostPeriod === $period ? ' selected' : '' ?>><?= nibe_escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Toon periode</button>
            </form>
            <?php $defrost = $performance['defrost_periods'][$selectedDefrostPeriod] ?? []; ?>
            <h3>Ontdooien — <?= nibe_escape($selectedDefrostLabel) ?></h3>
            <div class="metric-grid">
                <div class="metric">Aantal<strong><?= ($defrost['count'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape((string) $defrost['count']) ?></strong><?= nibe_debug($defrost['count'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['count']) ?></div>
                <div class="metric">Totale duur<strong><?= ($defrost['seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($defrost['seconds'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['seconds']) ?></div>
                <div class="metric">Gemiddelde defrostduur<strong><?= ($defrost['average_duration_seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['average_duration_seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($defrost['average_duration_seconds'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['average_duration_seconds']) ?></div>
                <div class="metric">Langste defrost<strong><?= ($defrost['longest_duration_seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['longest_duration_seconds'] / 60, 1, ',', '') . ' min') ?></strong><?= nibe_debug($defrost['longest_duration_seconds'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['longest_duration_seconds']) ?></div>
                <div class="metric">Gemiddeld interval<strong><?= ($defrost['average_interval_seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['average_interval_seconds'] / 3600, 1, ',', '') . ' uur') ?></strong><?= nibe_debug($defrost['average_interval_seconds'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['average_interval_seconds']) ?></div>
                <div class="metric">Tijd sinds laatste defrost<strong><?= ($defrost['since_last_seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['since_last_seconds'] / 3600, 1, ',', '') . ' uur') ?></strong><?= nibe_debug($defrost['since_last_seconds'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['since_last_seconds']) ?></div>
                <div class="metric">Gemiddelde buitentemperatuur bij defrost<strong><?= ($defrost['average_outdoor'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['average_outdoor'], 1, ',', '') . ' ' . ($roleUnits['outdoor'] ?? '')) ?></strong><?= nibe_debug($defrost['average_outdoor'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['average_outdoor']) ?></div>
                <div class="metric">Tijd tijdens draaien<strong><?= ($defrost['percent'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['percent'], 1, ',', '') . '%') ?></strong><?= nibe_debug($defrost['percent'] ?? null, $performance['debug']['defrost'][$selectedDefrostPeriod]['percent']) ?></div>
                <?php $heatSources = $defrost['heat_sources'] ?? null; ?>
                <?php foreach (['heating' => 'Warmtebron: verwarmingssysteem', 'hot_water' => 'Warmtebron: warm tapwater', 'auxiliary' => 'Warmtebron: elektrische bijverwarming', 'unknown' => 'Warmtebron: onbekend'] as $source => $label): ?>
                    <div class="metric"><?= nibe_escape($label) ?><strong><?= $heatSources === null ? 'Niet beschikbaar' : nibe_escape((string) $heatSources[$source]) ?></strong><?= nibe_debug($heatSources, $performance['debug']['defrost'][$selectedDefrostPeriod]['heat_sources']) ?></div>
                <?php endforeach; ?>
                <div class="metric">Datadekking<strong><?= nibe_escape(number_format($defrost['coverage_percentage'] ?? 0, 1, ',', '') . '%') ?></strong></div>
            </div>
        </section>
        <section class="panel" aria-labelledby="chart-title">
            <h2 id="chart-title">Regelgedrag en warmteafgifte vergelijken</h2>
            <p class="muted">Aanvoer, retour en berekende aanvoer tonen de warmteafgifte; graadminuten, compressorfrequentie en buitentemperatuur helpen regelpatronen te herkennen. Verschillende eenheden krijgen een eigen grafiek.</p>
            <div class="controls">
                <label>Periode
                    <select id="chart-period">
                        <option value="6h">6 uur</option>
                        <option value="1" selected>24 uur</option>
                        <option value="7">7 dagen</option>
                        <option value="30">30 dagen</option>
                        <option value="custom">Zelf kiezen</option>
                    </select>
                </label>
                <label>Van<input type="datetime-local" id="chart-start" disabled></label>
                <label>Tot<input type="datetime-local" id="chart-end" disabled></label>
                <button type="button" id="chart-show">Toon grafiek</button>
                <button type="button" id="chart-zoom-in" aria-label="Inzoomen">Inzoomen</button>
                <button type="button" id="chart-zoom-out" aria-label="Uitzoomen">Uitzoomen</button>
            </div>
            <p id="chart-message" class="muted" role="status">Grafiek laden…</p>
            <div id="charts" data-points="<?= nibe_escape(implode(',', $chartPointIds)) ?>"></div>
            <noscript>Voor het tonen van grafieken is JavaScript nodig.</noscript>
        </section>
        <details class="panel" id="measurement-list">
        <summary>Alle meetpunten (<?= count($measurements) ?>)</summary>
        <section class="measurements" aria-label="Laatste meetwaarden">
            <?php foreach ($measurements as $measurement): ?>
                <?php
                $freshnessTimestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'];
                $freshness = nibe_dashboard_measurement_freshness($freshnessTimestamp, $now);
                $isEmpty = $measurement['raw_value'] === null || $freshness['status'] === 'missing';
                $isStale = !$isEmpty && $freshness['status'] === 'stale';
                $age = $freshness['age_seconds'];
                $ageLabel = $age === null ? '' : ($age < 0
                    ? ' · ongeldige tijd'
                    : ($age < 60
                        ? ' · zojuist'
                        : ($age < 3600
                            ? ' · ' . floor($age / 60) . ' min geleden'
                            : ' · ' . floor($age / 3600) . ' uur geleden')));
                ?>
                <article class="measurement">
                    <h2><?= nibe_escape($measurement['name']) ?></h2>
                    <p class="device muted"><?= nibe_escape($measurement['device_name']) ?> · Meetpunt-ID: <?= nibe_escape((string) $measurement['measurement_point_id']) ?> · Bron-ID: <?= nibe_escape($measurement['source_point_id']) ?></p>
                    <p class="value">
                        <?= $measurement['raw_value'] === null ? 'Geen meting' : nibe_escape($measurement['raw_value']) ?>
                        <?php if (!$isEmpty && $measurement['unit'] !== null && $measurement['unit'] !== ''): ?>
                            <span class="unit"><?= nibe_escape($measurement['unit']) ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="status<?= $isEmpty ? '' : ($isStale ? ' stale' : ' live') ?>">
                        <?= $isEmpty ? 'Ontbrekend' : ($isStale ? 'Verouderd' : 'Live') . $ageLabel ?>
                    </p>
                    <?php if (!$isEmpty && is_string($freshnessTimestamp)): ?>
                        <small>Laatste meting: <time class="local-time" datetime="<?= nibe_escape($freshnessTimestamp) ?>">Tijd laden…</time></small>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        </details>
    <?php endif; ?>
    <section class="panel weather-panel" aria-labelledby="weather-title">
        <h2 id="weather-title">Weer in Grootegast</h2>
        <?php if (!$databaseReady): ?>
            <p class="muted">Weerdata is niet beschikbaar.</p>
        <?php elseif ($weather === null): ?>
            <p class="muted">Nog geen weerwaarneming opgeslagen. De collector haalt weerdata automatisch op.</p>
        <?php else: ?>
            <p><strong><?= nibe_escape(nibe_dashboard_weather_condition($weather['weather_code'])) ?></strong>
                · <?= nibe_escape(number_format($weather['temperature'], 1, ',', '') . ' ' . $weather['temperature_unit']) ?></p>
            <p class="muted">
                Gevoelstemperatuur <?= nibe_escape(number_format($weather['apparent_temperature'], 1, ',', '') . ' ' . $weather['temperature_unit']) ?>
                · Luchtvochtigheid <?= nibe_escape(number_format($weather['humidity'], 0, ',', '') . $weather['humidity_unit']) ?>
                · Neerslag <?= nibe_escape(number_format($weather['precipitation'], 1, ',', '') . ' ' . $weather['precipitation_unit']) ?>
                · Wind <?= nibe_escape(number_format($weather['wind_speed'], 1, ',', '') . ' ' . $weather['wind_speed_unit']) ?>
            </p>
            <small>Waargenomen: <time class="local-time" datetime="<?= nibe_escape($weather['timestamp']) ?>">Tijd laden…</time></small>
        <?php endif; ?>
    </section>
    <section class="panel" id="settings-log" aria-labelledby="settings-title">
        <h2 id="settings-title">Instellingenlogboek — handmatig</h2>
        <p class="muted">Leg zelf wijzigingen vast. Deze notities worden alleen lokaal in het dashboard opgeslagen; er worden nooit instellingen naar de warmtepomp geschreven.</p>
        <?php if ($settingsMessage !== null): ?><p role="status"><?= nibe_escape((string) $settingsMessage) ?></p><?php endif; ?>
        <?php if ($settingsError !== null): ?><p class="warning" role="alert"><?= nibe_escape($settingsError) ?></p><?php endif; ?>
        <?php if (!$databaseReady): ?>
            <p class="warning">Het instellingenlogboek is niet beschikbaar. Controleer of de database en tabel nibe_setting_changes zijn ingesteld.</p>
        <?php else: ?>
            <button type="button" id="setting-add">+ Instelling vastleggen</button>
            <?php if ($settingChanges === []): ?>
                <p class="muted">Nog geen instellingenwijzigingen vastgelegd.</p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Datum/tijd</th><th>Instelling</th><th>Wijziging</th><th>Opmerking</th><th>Acties</th></tr></thead>
                        <tbody>
                            <?php foreach ($settingChanges as $entry): ?>
                                <?php $entryDate = substr((string) $entry['changed_at'], 0, 16); ?>
                                <tr>
                                    <td><time class="local-time" datetime="<?= nibe_escape($entry['changed_at']) ?>">Tijd laden…</time></td>
                                    <td><?= nibe_escape($entry['setting']) ?></td>
                                    <td><?= $entry['old_value'] === null ? '' : nibe_escape($entry['old_value']) . ' → ' ?><?= nibe_escape($entry['new_value']) ?></td>
                                    <td><?= nl2br(nibe_escape($entry['note'])) ?></td>
                                    <td><div class="settings-actions">
                                        <button type="button" class="setting-edit"
                                            data-id="<?= nibe_escape((string) $entry['id']) ?>"
                                            data-setting="<?= nibe_escape($entry['setting']) ?>"
                                            data-old-value="<?= nibe_escape($entry['old_value']) ?>"
                                            data-new-value="<?= nibe_escape($entry['new_value']) ?>"
                                            data-note="<?= nibe_escape($entry['note']) ?>"
                                            data-changed-at="<?= nibe_escape(str_replace(' ', 'T', $entryDate)) ?>">Bewerken</button>
                                        <form method="post" class="setting-delete">
                                            <input type="hidden" name="csrf_token" value="<?= nibe_escape($settingsCsrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= nibe_escape((string) $entry['id']) ?>">
                                            <button type="submit">Verwijderen</button>
                                        </form>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <dialog id="setting-dialog" aria-labelledby="setting-dialog-title">
                <form method="post" class="settings-form" id="setting-form">
                    <h3 id="setting-dialog-title">Instelling vastleggen</h3>
                    <input type="hidden" name="csrf_token" value="<?= nibe_escape($settingsCsrfToken) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="">
                    <label>Instelling<input name="setting" maxlength="191" required></label>
                    <label>Nieuwe waarde<input name="new_value" maxlength="1000" required></label>
                    <label>Vorige waarde (optioneel)<input name="old_value" maxlength="1000"></label>
                    <label>Datum en tijd<input type="datetime-local" name="changed_at" required></label>
                    <label>Opmerking (optioneel)<textarea name="note" maxlength="5000"></textarea></label>
                    <div class="settings-actions-row">
                        <button type="submit">Opslaan</button>
                        <button type="button" id="setting-cancel">Annuleren</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
    </section>
    <script src="dashboard.js" defer></script>
</body>
</html>
