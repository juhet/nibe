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
$performance = [];
$roleUnits = [];
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

    $historyRoles = ['frequency', 'supply', 'return', 'calculated_supply', 'outdoor', 'degree_minutes',
        'electric_power', 'heat_power', 'electric_energy', 'heat_energy', 'defrost'];
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
        foreach (['defrost', 'electric_energy', 'heat_energy'] as $role) {
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
} catch (Throwable $exception) {
    $databaseReady = false;
}

$installationLabel = nibe_env('NIBE_INSTALLATION_LABEL', 'NIBE-installatie');
$performance += [
    'periods' => [],
    'cop_now' => null,
    'cop_warning' => false,
    'delta_t_now' => null,
    'compressor_starts' => null,
    'average_run_seconds' => null,
    'longest_run_seconds' => null,
    'shortest_run_seconds' => null,
    'frequency_distribution' => [],
    'defrost_periods' => [],
];
$selectedDefrostPeriod = $_GET['defrost_period'] ?? '24h';
if (!is_string($selectedDefrostPeriod)
    || !in_array($selectedDefrostPeriod, ['24h', '7d', '30d'], true)) {
    $selectedDefrostPeriod = '24h';
}
$selectedDefrostLabel = ['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'][$selectedDefrostPeriod];
$currentHeatPower = null;
if (isset($defaultRoleMapping['heat_power'], $measurementById[$defaultRoleMapping['heat_power']])) {
    $heatPowerMeasurement = $measurementById[$defaultRoleMapping['heat_power']];
    $freshness = $heatPowerMeasurement['source_timestamp'] ?? $heatPowerMeasurement['collected_at'];
    if (is_string($heatPowerMeasurement['raw_value']) && is_numeric($heatPowerMeasurement['raw_value'])
        && is_string($freshness)) {
        try {
            $freshnessTime = (new DateTimeImmutable($freshness, new DateTimeZone('UTC')))->getTimestamp();
            if ($freshnessTime >= $now->getTimestamp() - 900 && $freshnessTime <= $now->getTimestamp()) {
                $currentHeatPower = $heatPowerMeasurement['raw_value'] . ' ' . ($roleUnits['heat_power'] ?? '');
            }
        } catch (Exception $exception) {
            $currentHeatPower = null;
        }
    }
}
$importantRoles = [
    'indoor' => 'Temperatuur in huis',
    'hot_water' => 'Temperatuur warm tapwater',
    'outdoor' => 'Buitentemperatuur',
    'degree_minutes' => 'Graadminuten',
    'frequency' => 'Compressorfrequentie',
    'calculated_supply' => 'Berekende toevoer',
    'supply' => 'Aanvoer',
    'return' => 'Retour',
    'heat_power' => 'Warmtevermogen',
    'electric_power' => 'Elektrisch vermogen',
];
$chartRoleOrder = ['hot_water', 'calculated_supply', 'supply', 'return', 'indoor', 'outdoor',
    'degree_minutes', 'frequency'];
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
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: .5rem 1rem; }
        .metric { padding: .7rem; background: #f3f6f8; border-radius: .4rem; }
        .metric strong { display: block; font-size: 1.1rem; }
        .warning { padding: .65rem; color: #8a4b00; background: #fff3cd; border-radius: .4rem; }
        .bands { display: grid; grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr)); gap: .5rem; }
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
            <p class="muted">Compressor en warmteafgifte — afgelopen 24 uur</p>
            <div class="kpis">
                <div class="kpi">Compressorstarts<strong><?= $performance['compressor_starts'] === null ? 'Niet beschikbaar' : nibe_escape((string) $performance['compressor_starts']) ?></strong></div>
                <div class="kpi">Gemiddelde runduur<strong><?= $performance['average_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['average_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                <div class="kpi">Langste runduur<strong><?= $performance['longest_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['longest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                <div class="kpi">Kortste runduur<strong><?= $performance['shortest_run_seconds'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['shortest_run_seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                <div class="kpi">ΔT nu<strong><?= $performance['delta_t_now'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['delta_t_now'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong></div>
                <div class="kpi">Gemiddelde ΔT<strong><?= $performance['periods']['24h']['average_delta_t'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['24h']['average_delta_t'], 1, ',', '') . ' ' . ($roleUnits['supply'] ?? '')) ?></strong></div>
                <div class="kpi">Warmtevermogen nu<strong><?= $currentHeatPower === null ? 'Niet beschikbaar' : nibe_escape($currentHeatPower) ?></strong></div>
            </div>
            <p class="muted">ΔT nu vereist aanvoer en retour met dezelfde eenheid, maximaal 5 minuten tussen de brontijden en een recentste meting van maximaal 15 minuten oud. “Live” bij de meetpunten betekent minder dan 24 uur oud en is dus minder strikt.</p>
        </section>
        <section class="panel" aria-labelledby="performance-title">
            <h2 id="performance-title">Prestatie-inzichten</h2>
            <h3>Efficiëntie (COP) en energie</h3>
            <div class="metric-grid" aria-label="COP">
                <div class="metric">COP nu<strong><?= $performance['cop_now'] === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['cop_now'], 2, ',', '')) ?></strong></div>
                <div class="metric">COP vandaag<strong><?= ($performance['periods']['today']['cop'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['cop'], 2, ',', '')) ?></strong></div>
                <?php foreach (['24h' => '24 uur', '7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                    <div class="metric">COP <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['cop'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['cop'], 2, ',', '')) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <?php if ($performance['cop_warning']): ?>
                <p class="warning" role="status">COP nu wijkt meer dan 20% af van het gemiddelde van de afgelopen 24 uur.</p>
            <?php endif; ?>
            <div class="kpis">
                <div class="kpi">Elektriciteit vandaag (UTC)<strong><?= ($performance['periods']['today']['electricity'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['electricity'], 2, ',', '') . ' kWh') ?></strong></div>
                <div class="kpi">Warmte vandaag (UTC)<strong><?= ($performance['periods']['today']['heat'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods']['today']['heat'], 2, ',', '') . ' kWh') ?></strong></div>
                <?php foreach (['7d' => '7 dagen', '30d' => '30 dagen'] as $period => $label): ?>
                    <div class="kpi">Elektriciteit <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['electricity'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['electricity'], 2, ',', '') . ' kWh') ?></strong></div>
                    <div class="kpi">Warmte <?= nibe_escape($label) ?><strong><?= ($performance['periods'][$period]['heat'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['periods'][$period]['heat'], 2, ',', '') . ' kWh') ?></strong></div>
                <?php endforeach; ?>
            </div>
            <h3>Compressorfrequentie — afgelopen 24 uur</h3>
            <div class="bands">
                <?php foreach (['20-30' => '20–30 Hz', '30-40' => '30–40 Hz', '40-50' => '40–50 Hz', '50-60' => '50–60 Hz', '60+' => '60 Hz of hoger'] as $band => $label): ?>
                    <div class="metric"><?= nibe_escape($label) ?><strong><?= ($performance['frequency_distribution'][$band] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($performance['frequency_distribution'][$band], 1, ',', '') . '%') ?></strong></div>
                <?php endforeach; ?>
            </div>
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
                <div class="metric">Aantal<strong><?= ($defrost['count'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape((string) $defrost['count']) ?></strong></div>
                <div class="metric">Totale duur<strong><?= ($defrost['seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['seconds'] / 60, 1, ',', '') . ' min') ?></strong></div>
                <div class="metric">Gemiddeld interval<strong><?= ($defrost['average_interval_seconds'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['average_interval_seconds'] / 3600, 1, ',', '') . ' uur') ?></strong></div>
                <div class="metric">Tijd tijdens draaien<strong><?= ($defrost['percent'] ?? null) === null ? 'Niet beschikbaar' : nibe_escape(number_format($defrost['percent'], 1, ',', '') . '%') ?></strong></div>
            </div>
            <p class="muted">COP vereist gekoppeld elektrisch vermogen en warmtevermogen (W of kW), met meettijden binnen 5 minuten en een positief elektrisch vermogen. Voor COP nu moet de meting maximaal 15 minuten oud zijn. Warmtevermogen kan niet uit temperaturen alleen worden bepaald.</p>
            <p class="muted">Energie vereist cumulatieve tellers in kWh of Wh met een meting binnen 6 uur vóór de periode en een eindmeting van maximaal 6 uur oud. Uurwaarden en jaarwaarden die periodiek worden gereset zijn geen geschikte cumulatieve tellers. Ontdooicijfers vereisen een gekoppelde status, voldoende recente historie en een meting binnen 6 uur vóór de periode; het gemiddelde interval vereist minstens twee waargenomen starts.</p>
            <p class="muted">Koppelingen voor prestatie-inzichten (aanpassen via NIBE_DASHBOARD_ROLE_MAP):</p>
            <ul>
                <?php foreach (['electric_power' => 'Elektrisch vermogen', 'heat_power' => 'Warmtevermogen', 'electric_energy' => 'Elektriciteitsteller', 'heat_energy' => 'Warmteteller', 'defrost' => 'Ontdooistatus'] as $role => $label): ?>
                    <li><?= nibe_escape($label) ?>: <?php if (isset($defaultRoleMapping[$role], $measurementById[$defaultRoleMapping[$role]])): ?>
                        <?= nibe_escape($measurementById[$defaultRoleMapping[$role]]['name']) ?> (Meetpunt-ID: <?= nibe_escape($defaultRoleMapping[$role]) ?>)
                    <?php else: ?>Niet gekoppeld<?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="panel" aria-labelledby="chart-title">
            <h2 id="chart-title">Regelgedrag en warmteafgifte vergelijken</h2>
            <p class="muted">Aanvoer, retour en berekende aanvoer tonen de warmteafgifte; graadminuten, compressorfrequentie en buitentemperatuur helpen regelpatronen te herkennen. Verschillende eenheden krijgen een eigen grafiek.</p>
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
                    <p class="device muted"><?= nibe_escape($measurement['device_name']) ?> · Meetpunt-ID: <?= nibe_escape((string) $measurement['measurement_point_id']) ?> · Bron-ID: <?= nibe_escape($measurement['source_point_id']) ?></p>
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
