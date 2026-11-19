<?php

require_once __DIR__ . '/../app/dashboard.php';

function expect_same(mixed $expected, mixed $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $case,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$trend = nibe_dashboard_sparkline([
    ['raw_value' => 'running', 'source_timestamp' => '2026-01-02 03:04:05', 'collected_at' => '2026-01-02 03:04:05'],
    ['raw_value' => '0', 'source_timestamp' => '2026-01-02 03:04:06', 'collected_at' => '2026-01-02 03:04:06'],
    ['raw_value' => 'NaN', 'source_timestamp' => '2026-01-02 03:04:07', 'collected_at' => '2026-01-02 03:04:07'],
], '<&');
expect_same(1, substr_count($trend, '<circle '), 'only finite numeric observations are charted');
expect_same(true, str_contains($trend, 'Bron tijd (UTC): 2026-01-02 03:04:06 — 0 &lt;&amp;'), 'chart labels escape source data and preserve zero');
expect_same(null, nibe_dashboard_sparkline([
    ['raw_value' => 'false', 'source_timestamp' => null, 'collected_at' => '2026-01-02 03:04:05'],
], null), 'non-numeric observations do not produce a chart');
expect_same(null, nibe_dashboard_sparkline([], null), 'empty history does not produce a chart');

$deviceId = 'emmy-n-20251117-06794225149019-fc-0f-e7-5e-ef-32';
$sourcePointIds = [
    'indoor' => '40033',
    'hot_water' => '40013',
    'outdoor' => '44362',
    'degree_minutes' => '40941',
    'frequency' => '44701',
    'calculated_supply' => '43009',
    'supply' => '44058',
    'return' => '44055',
    'electric_power' => '32486',
    'defrost' => '44703',
];
$measurements = [
    ['measurement_point_id' => '99', 'source_device_id' => 'another-device', 'source_point_id' => '40033'],
];
$expectedMapping = [];
foreach ($sourcePointIds as $role => $sourcePointId) {
    $measurementId = (string) (count($expectedMapping) + 1);
    $measurements[] = [
        'measurement_point_id' => $measurementId,
        'source_device_id' => $deviceId,
        'source_point_id' => $sourcePointId,
    ];
    $expectedMapping[$role] = $measurementId;
}
expect_same($expectedMapping, nibe_dashboard_default_role_mapping($measurements), 'default roles match exact device and source point IDs');
expect_same('1', nibe_dashboard_role_mapping($measurements, '{"electric_power":"1","defrost":"1"}')['defrost'],
    'configured roles override defaults');
expect_same('Aanvoer', nibe_dashboard_point_name([
    'source_device_id' => $deviceId,
    'source_point_id' => '44058',
    'name' => 'Supply line (EB101-BT12)',
]), 'known points use friendly names');
expect_same('Supply line (EB101-BT12)', nibe_dashboard_point_name([
    'source_device_id' => 'another-device',
    'source_point_id' => '44058',
    'name' => 'Supply line (EB101-BT12)',
]), 'other devices preserve their source point name');
expect_same(['electric_power' => '13', 'heat_power' => '598'], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '13', 'source_device_id' => $deviceId, 'source_point_id' => '13'],
    ['measurement_point_id' => '598', 'source_device_id' => $deviceId, 'source_point_id' => '12345',
        'name' => 'Heat capacity (EB101)', 'unit' => 'kW'],
]), 'heat capacity maps the local measurement ID rather than assuming it is the source ID');
expect_same(['heat_power' => '700'], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '700', 'source_device_id' => 'another-device', 'source_point_id' => '12345',
        'name' => 'Heat capacity (EB101)', 'unit' => 'W'],
]), 'heat capacity is mapped when its local ID and device differ');
expect_same([], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '700', 'source_point_id' => '598', 'name' => 'Other power', 'unit' => 'kW'],
    ['measurement_point_id' => '701', 'name' => 'Heat capacity (EB101)', 'unit' => 'kWh'],
]), 'a source ID or incompatible heat capacity unit alone does not establish heat power');
expect_same([], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '700', 'name' => 'Heat capacity (EB101)', 'unit' => 'kW'],
    ['measurement_point_id' => '701', 'name' => 'Heat capacity (EB101)', 'unit' => 'W'],
]), 'ambiguous heat capacity points require explicit configuration');
expect_same('701', nibe_dashboard_role_mapping([
    ['measurement_point_id' => '700', 'name' => 'Heat capacity (EB101)', 'unit' => 'kW'],
    ['measurement_point_id' => '701', 'name' => 'Heat capacity (EB101)', 'unit' => 'W'],
], '{"heat_power":"701"}')['heat_power'], 'explicit heat power mapping resolves ambiguity');

$kpis = nibe_dashboard_kpis([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2025-12-26 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '20', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '30', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:40:00'],
        ['raw_value' => '25', 'source_timestamp' => '2026-01-02 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 12:00:00'],
    ],
    'supply' => [
        ['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '42', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '41', 'source_timestamp' => '2026-01-02 11:20:00'],
    ],
    'return' => [
        ['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:01:00'],
        ['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:11:00'],
        ['raw_value' => '34', 'source_timestamp' => '2026-01-02 11:21:00'],
    ],
], '°C', 'C', new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(2, $kpis['compressor_starts'], 'compressor starts are counted on zero-to-positive transitions');
expect_same(6.3, round($kpis['average_delta_t'], 1), 'delta T averages nearby same-unit supply and return samples');
expect_same(900, $kpis['average_run_seconds'], 'average compressor runtime uses completed runs');
expect_same(null, nibe_dashboard_kpis([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '20', 'source_timestamp' => '2026-01-02 11:10:00'],
    ],
    'supply' => [['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:00:00']],
    'return' => [['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:01:00']],
], '°C', '°F', new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')))['average_delta_t'], 'delta T is unavailable for incompatible units');

$configuredMapping = nibe_dashboard_role_mapping([
    ['measurement_point_id' => '101'],
    ['measurement_point_id' => '102'],
], '{"electric_power":101,"heat_power":"102","defrost":"999"}');
expect_same(['electric_power' => '101', 'heat_power' => '102'], array_intersect_key(
    $configuredMapping,
    array_flip(['electric_power', 'heat_power', 'defrost'])
), 'configured roles only accept discovered measurement point IDs');

$performance = nibe_dashboard_performance_metrics([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2025-12-26 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '25', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '55', 'source_timestamp' => '2026-01-02 11:40:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 12:00:00'],
    ],
    'electric_power' => [['raw_value' => '500', 'source_timestamp' => '2026-01-02 11:50:00']],
    'heat_power' => [['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:50:00']],
    'electric_energy' => [
        ['raw_value' => '10', 'source_timestamp' => '2026-01-01 23:00:00'],
        ['raw_value' => '12', 'source_timestamp' => '2026-01-02 11:00:00'],
    ],
    'heat_energy' => [
        ['raw_value' => '30', 'source_timestamp' => '2026-01-01 23:00:00'],
        ['raw_value' => '36', 'source_timestamp' => '2026-01-02 11:00:00'],
    ],
    'defrost' => [
        ['raw_value' => '0', 'source_timestamp' => '2025-12-03 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2025-12-26 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:15:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:25:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:35:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:40:00'],
    ],
    'supply' => [['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:58:00']],
    'return' => [['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:59:00']],
], [
    'electric_power' => 'W',
    'heat_power' => 'kW',
    'electric_energy' => 'kWh',
    'heat_energy' => 'kWh',
    'supply' => '°C',
    'return' => 'C',
], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(4.0, $performance['cop_now'], 'COP converts compatible power units');
expect_same(4.0, $performance['periods']['today']['cop'], 'daily COP is the ratio of integrated power');
expect_same('2026-01-02 11:50:00', $performance['cop_now_timestamp'], 'current COP exposes the measurement time');
expect_same(0.04, round($performance['periods']['today']['electricity'], 2), 'electric energy integrates W samples at five minutes each');
expect_same(0.17, round($performance['periods']['today']['heat'], 2), 'heat energy integrates kW samples at five minutes each');
$pairedPower = nibe_dashboard_performance_metrics([
    'electric_power' => [
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:25:00'],
        ['raw_value' => '4', 'source_timestamp' => '2026-01-02 11:35:00'],
        ['raw_value' => '8', 'source_timestamp' => '2026-01-02 11:55:01'],
    ],
    'heat_power' => [
        ['raw_value' => '8', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '8', 'source_timestamp' => '2026-01-02 11:31:00'],
        ['raw_value' => '8', 'source_timestamp' => '2026-01-02 11:50:00'],
    ],
], ['electric_power' => 'kW', 'heat_power' => 'kW'],
    new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(1.6, round($pairedPower['periods']['today']['cop'], 2),
    'historical COP is the ratio of the separately integrated power measurements');
expect_same(1.25, $pairedPower['periods']['today']['electricity'], 'electricity integrates all five-minute power samples');
expect_same(2.0, $pairedPower['periods']['today']['heat'], 'heat integrates all five-minute power samples');
$currentCop = nibe_dashboard_performance_metrics([
    'electric_power' => [['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:45:00']],
    'heat_power' => [['raw_value' => '3', 'source_timestamp' => '2026-01-02 11:55:00']],
], ['electric_power' => 'kW', 'heat_power' => 'kW'],
    new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(1.5, $currentCop['cop_now'], 'current COP accepts separate latest samples up to fifteen minutes old');
expect_same('2026-01-02 11:55:00', $currentCop['cop_now_timestamp'], 'current COP time is the later input sample time');
expect_same(1.5, $currentCop['periods']['today']['cop'], 'historical COP does not require artificially paired timestamps');
expect_same(0.04, round($performance['periods']['today']['electricity'], 2), 'power integration takes precedence over optional cumulative counters');
expect_same(0.17, round($performance['periods']['today']['heat'], 2), 'heat production is integrated from heat power');
expect_same(2, $performance['compressor_starts'], 'performance metrics count compressor starts');
expect_same(2, $performance['compressor_starts_7d'], 'compressor starts are also counted over seven days');
expect_same(1200, $performance['longest_run_seconds'], 'longest completed compressor run is reported');
expect_same(600, $performance['shortest_run_seconds'], 'shortest completed compressor run is reported');
expect_same(16.7, round($performance['frequency_distribution']['20-30'], 1), 'frequency band percentages use total observed time');
expect_same(50, $performance['frequency_distribution']['0'], 'the zero-hertz band includes time when the compressor is stopped');
expect_same(1800, $performance['frequency_seconds']['0'], 'frequency bands expose total time per band');
$allFrequencyBands = nibe_dashboard_performance_metrics([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:55:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 10:55:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '10', 'source_timestamp' => '2026-01-02 11:05:00'],
        ['raw_value' => '20', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '30', 'source_timestamp' => '2026-01-02 11:15:00'],
        ['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '50', 'source_timestamp' => '2026-01-02 11:25:00'],
        ['raw_value' => '60', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '60', 'source_timestamp' => '2026-01-02 11:35:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:40:00'],
    ],
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
foreach (['0', '1-20', '20-30', '30-40', '40-50', '50-60', '60+'] as $band) {
    expect_same(true, $allFrequencyBands['frequency_seconds'][$band] > 0, 'frequency band ' . $band . ' accumulates time');
}
expect_same(2, $performance['defrost_periods']['24h']['count'], 'defrost transitions are counted');
expect_same(900, $performance['defrost_periods']['24h']['seconds'], 'defrost time is measured from observed samples');
expect_same(450, $performance['defrost_periods']['24h']['average_duration_seconds'], 'average duration uses completed defrost cycles');
expect_same(900, $performance['defrost_periods']['24h']['average_interval_seconds'], 'average interval is exposed when at least two defrosts are observed');
expect_same(null, nibe_dashboard_performance_metrics([
    'defrost' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:05:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:10:00'],
    ],
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'))
)['defrost_periods']['24h']['average_interval_seconds'], 'average interval is unavailable with only one defrost');
expect_same(2, $performance['defrost_periods']['7d']['count'], 'defrost metrics support a selected seven-day period');
expect_same(5.0, $performance['delta_t_now'], 'current delta T requires recent matching measurements with compatible temperature units');
expect_same(5.0, $performance['periods']['7d']['average_delta_t'], 'average delta T is available for seven days');
expect_same(null, nibe_dashboard_performance_metrics([
    'supply' => [['raw_value' => '25.8', 'source_timestamp' => '2026-01-02 11:40:00', 'collected_at' => '2026-01-02 12:00:00']],
    'return' => [['raw_value' => '26.2', 'source_timestamp' => '2026-01-02 11:41:00', 'collected_at' => '2026-01-02 12:00:00']],
], ['supply' => '°C', 'return' => '°C'], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'))
)['delta_t_now'], 'recent collection does not make old source temperatures current');
expect_same(null, nibe_dashboard_performance_metrics([
    'supply' => [['raw_value' => '25.8', 'source_timestamp' => '2026-01-02 11:50:00']],
    'return' => [['raw_value' => '26.2', 'source_timestamp' => '2026-01-02 11:56:00']],
], ['supply' => '°C', 'return' => '°C'], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'))
)['delta_t_now'], 'current delta T does not combine temperatures more than five minutes apart');
expect_same(null, nibe_dashboard_performance_metrics([
    'electric_power' => [['raw_value' => '500', 'source_timestamp' => '2026-01-02 11:50:00']],
    'heat_power' => [['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:50:00']],
], ['electric_power' => 'unknown', 'heat_power' => 'unknown'],
    new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'))
)['periods']['24h']['cop'], 'unsupported power units leave COP unavailable');
expect_same(null, nibe_dashboard_performance_metrics([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 10:00:00'],
        ['raw_value' => '20', 'source_timestamp' => '2026-01-01 11:00:00'],
    ],
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')))['compressor_starts'],
    'stale compressor history does not appear as zero recent starts');
$staleDefrost = nibe_dashboard_performance_metrics([
    'defrost' => [
        ['raw_value' => '0', 'source_timestamp' => '2025-12-03 11:00:00'],
        ['raw_value' => '1', 'source_timestamp' => '2025-12-03 13:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2025-12-03 13:05:00'],
        ['raw_value' => '1', 'source_timestamp' => '2025-12-04 13:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2025-12-04 13:05:00'],
    ],
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(null, $staleDefrost['defrost_periods']['30d']['average_interval_seconds'],
    'stale defrost samples do not produce an average interval');

$debugNow = new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'));
$debugMetrics = nibe_dashboard_performance_metrics([
    'supply' => [['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:50:00']],
    'return' => [['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:55:05']],
    'electric_power' => [['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:45:00']],
    'heat_power' => [['raw_value' => '3', 'source_timestamp' => '2026-01-02 11:44:59']],
], ['supply' => '°C', 'return' => '°C', 'electric_power' => 'kW', 'heat_power' => 'kW'], $debugNow);
expect_same(null, $debugMetrics['delta_t_now'], 'delta T rejects a 305-second gap');
expect_same(true, in_array(['ok' => false, 'message' => 'Tijdsverschil: 305 seconden (maximaal 300)'],
    $debugMetrics['debug']['delta_t_now'], true), 'delta T debug reports the exact failed gap');
expect_same(null, $debugMetrics['cop_now'], 'COP rejects heat power older than 15 minutes');
expect_same(true, in_array(['ok' => false, 'message' => 'Warmtevermogen: meting 901 seconden oud (maximaal 900)'],
    $debugMetrics['debug']['cop_now'], true), 'COP debug reports the exact heat power age');
expect_same(true, in_array(['ok' => true, 'message' => 'Elektrisch vermogen: meting 900 seconden oud (maximaal 900)'],
    $debugMetrics['debug']['cop_now'], true), 'COP debug accepts the 15-minute boundary');

$counterDebug = nibe_dashboard_performance_metrics([
    'heat_energy' => [
        ['raw_value' => '10', 'source_timestamp' => '2026-01-01 23:00:00'],
        ['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:55:00'],
    ],
], ['heat_energy' => 'kWh'], $debugNow);
expect_same(null, $counterDebug['periods']['today']['heat'], 'reset counter cannot supply energy');
expect_same(true, in_array(['ok' => false, 'message' => 'Eindstand is niet lager dan beginstand (2 tegenover 10)'],
    $counterDebug['debug']['periods']['today']['heat'], true), 'energy debug explains counter reset');

$invalidDebug = nibe_dashboard_performance_metrics([
    'electric_power' => [['raw_value' => 'not numeric', 'source_timestamp' => '2026-01-02 11:55:00']],
], ['electric_power' => 'W'], $debugNow);
expect_same(true, in_array(['ok' => false, 'message' => 'Elektrisch vermogen: waarde is geen numerieke string'],
    $invalidDebug['debug']['cop_now'], true), 'debug explains why observations were rejected');

$emptyDebug = nibe_dashboard_performance_metrics([], [], $debugNow);
$unavailableChecks = [
    $emptyDebug['debug']['cop_now'], $emptyDebug['debug']['delta_t_now'],
    $emptyDebug['debug']['runtime'], $emptyDebug['debug']['frequency_distribution'],
    $emptyDebug['debug']['frequency']['24h'], $emptyDebug['debug']['frequency']['7d'],
];
foreach (['today', '24h', '7d', '30d'] as $period) {
    foreach (['cop', 'electricity', 'heat', 'average_delta_t'] as $metric) {
        $unavailableChecks[] = $emptyDebug['debug']['periods'][$period][$metric];
    }
}
foreach (['24h', '7d', '30d'] as $period) {
    foreach (['count', 'seconds', 'average_duration_seconds', 'average_interval_seconds', 'percent'] as $metric) {
        $unavailableChecks[] = $emptyDebug['debug']['defrost'][$period][$metric];
    }
}
foreach ($unavailableChecks as $checks) {
    expect_same(true, count(array_filter($checks, static fn (array $check): bool => !$check['ok'])) > 0,
        'every unavailable metric has at least one failing debug check');
}

$request = nibe_dashboard_history_request(['points' => '1,22', 'start' => '100', 'end' => '200']);
expect_same(['points' => ['1', '22'], 'start' => 100, 'end' => 200], $request, 'valid history request');
expect_same(['1', '2', '3', '4', '5', '6', '7', '8'], nibe_dashboard_history_request([
    'points' => '1,2,3,4,5,6,7,8',
    'start' => '100',
    'end' => '200',
])['points'], 'history requests support all default measurement points');
foreach ([
    ['points' => '1,1', 'start' => '100', 'end' => '200'],
    ['points' => '1,2,3,4,5,6,7,8,9', 'start' => '100', 'end' => '200'],
    ['points' => '1 OR 1=1', 'start' => '100', 'end' => '200'],
    ['points' => '1', 'start' => '100', 'end' => '2592101'],
    ['points' => '1', 'start' => '200', 'end' => '100'],
    ['points' => ['1'], 'start' => '100', 'end' => '200'],
] as $invalid) {
    expect_same(null, nibe_dashboard_history_request($invalid), 'invalid history request');
}

echo "Dashboard tests passed.\n";
