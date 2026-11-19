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

$kpis = nibe_dashboard_kpis([
    'frequency' => [
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
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '25', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '55', 'source_timestamp' => '2026-01-02 11:40:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:50:00'],
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
expect_same(4.0, $performance['periods']['today']['cop'], 'daily COP averages observed power ratios');
expect_same(2.0, $performance['periods']['today']['electricity'], 'energy consumption uses cumulative kWh readings');
expect_same(6.0, $performance['periods']['today']['heat'], 'heat production uses cumulative kWh readings');
expect_same(2, $performance['compressor_starts'], 'performance metrics count compressor starts');
expect_same(1200, $performance['longest_run_seconds'], 'longest completed compressor run is reported');
expect_same(600, $performance['shortest_run_seconds'], 'shortest completed compressor run is reported');
expect_same(33.3, round($performance['frequency_distribution']['20-30'], 1), 'frequency band percentages use observed compressor runtime');
expect_same(1, $performance['defrost_periods']['24h']['count'], 'defrost transitions are counted');
expect_same(600, $performance['defrost_periods']['24h']['seconds'], 'defrost time is measured from observed samples');
expect_same(1, $performance['defrost_periods']['7d']['count'], 'defrost metrics support a selected seven-day period');
expect_same(5.0, $performance['delta_t_now'], 'current delta T requires recent matching measurements with compatible temperature units');
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
