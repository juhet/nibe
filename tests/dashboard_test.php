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

function complete_frequency_history(array $events, string $start, string $end): array
{
    usort($events, static fn (array $left, array $right): int => strcmp($left['source_timestamp'], $right['source_timestamp']));
    $startTime = strtotime($start . ' UTC');
    $endTime = strtotime($end . ' UTC');
    $eventIndex = 0;
    $value = '0';
    $samples = [];
    for ($time = $startTime; $time <= $endTime; $time += 300) {
        while (isset($events[$eventIndex])
            && strtotime($events[$eventIndex]['source_timestamp'] . ' UTC') <= $time) {
            $value = $events[$eventIndex]['raw_value'];
            $eventIndex++;
        }
        $samples[$time] = ['raw_value' => $value, 'source_timestamp' => gmdate('Y-m-d H:i:s', $time)];
    }
    foreach ($events as $event) {
        $time = strtotime($event['source_timestamp'] . ' UTC');
        if ($time >= $startTime && $time <= $endTime) {
            $samples[$time] = $event;
        }
    }
    ksort($samples);
    return array_values($samples);
}

$now = new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC'));
expect_same('live', nibe_dashboard_measurement_freshness('2026-01-02 11:50:00', $now)['status'],
    'measurements within fifteen minutes are live');
expect_same('stale', nibe_dashboard_measurement_freshness('2026-01-02 11:44:59', $now)['status'],
    'measurements older than fifteen minutes are stale');
expect_same('missing', nibe_dashboard_measurement_freshness(null, $now)['status'],
    'missing timestamps do not appear live');
$weather = nibe_dashboard_weather([
    'source_timestamp' => '2026-01-02 11:00:00',
    'raw_data' => json_encode([
        'current' => [
            'temperature_2m' => 3.2,
            'relative_humidity_2m' => 84,
            'apparent_temperature' => 0.8,
            'precipitation' => 0.1,
            'weather_code' => 61,
            'wind_speed_10m' => 12.4,
        ],
        'current_units' => [
            'temperature_2m' => '°C',
            'relative_humidity_2m' => '%',
            'apparent_temperature' => '°C',
            'precipitation' => 'mm',
            'weather_code' => 'wmo code',
            'wind_speed_10m' => 'km/h',
        ],
    ], JSON_THROW_ON_ERROR),
]);
expect_same(3.2, $weather['temperature'], 'weather observations are decoded for display');
expect_same('Regen', nibe_dashboard_weather_condition($weather['weather_code']), 'WMO weather codes have Dutch descriptions');
expect_same(null, nibe_dashboard_weather(['source_timestamp' => 'now', 'raw_data' => '{']),
    'invalid weather JSON is unavailable');
$noSuggestions = nibe_dashboard_suggestions([]);
expect_same([], $noSuggestions, 'missing metrics do not produce unsupported suggestions');
$cyclingSuggestionInput = [
    'compressor_periods' => ['24h' => [
        'complete' => true,
        'starts' => 73,
        'average_run_seconds' => 1800,
        'coverage_percentage' => 95.0,
    ]],
];
expect_same(1, count(nibe_dashboard_suggestions($cyclingSuggestionInput)),
    'frequent short compressor runs produce an evidence-based suggestion');
expect_same(0, count(nibe_dashboard_suggestions([
    'compressor_periods' => ['24h' => [
        'complete' => false,
        'starts' => 100,
        'average_run_seconds' => 1200,
        'coverage_percentage' => 50.0,
    ]],
])), 'incomplete compressor data does not produce a suggestion');
expect_same(0, count(nibe_dashboard_suggestions([
    'compressor_periods' => ['24h' => [
        'complete' => true,
        'starts' => 72,
        'average_run_seconds' => 1800,
        'coverage_percentage' => 100.0,
    ]],
])), 'exactly three compressor starts per hour does not exceed the conservative threshold');
$threeSuggestions = nibe_dashboard_suggestions($cyclingSuggestionInput + [
    'auxiliary_heat_periods' => ['24h' => ['starts' => 2, 'electricity_kwh' => 1.25]],
    'cop_warning' => true,
    'cop_now' => 1.0,
    'periods' => ['24h' => ['cop' => 1.5]],
]);
expect_same(3, count($threeSuggestions), 'dashboard suggestions are capped at three');
expect_same(true, str_contains($threeSuggestions[0]['evidence'], '73 starts'),
    'suggestions include the measured evidence supporting them');
$settingEntry = nibe_dashboard_setting_change_input([
    'setting' => 'Graadminuten start compressor',
    'new_value' => '-180',
    'old_value' => '-100',
    'changed_at' => '2026-11-22T20:15',
    'note' => 'Lange runs',
]);
expect_same('2026-11-22 20:15:00', $settingEntry['changed_at'], 'manual setting timestamps are stored as UTC');
expect_same(null, nibe_dashboard_setting_change_input([
    'setting' => 'Stooklijn',
    'new_value' => '6',
    'old_value' => '',
    'changed_at' => '2026-11-22T20:15',
    'note' => '',
])['old_value'], 'previous setting values are optional');
try {
    nibe_dashboard_setting_change_input([
        'setting' => 'Invalid',
        'new_value' => '1',
        'changed_at' => '2026-02-30T20:15',
    ]);
    throw new RuntimeException('Invalid calendar dates must be rejected.');
} catch (InvalidArgumentException) {
}
$cycleStart = strtotime('2026-01-02 10:00:00 UTC');
$frequencyAt = static fn (int $offset, int $value): array => [
    'time' => $cycleStart + $offset,
    'value' => $value,
];
$detectedCycles = nibe_dashboard_compressor_cycles([
    'frequency' => [
        $frequencyAt(0, 0), $frequencyAt(300, 30), $frequencyAt(600, 35),
        $frequencyAt(5000, 0), $frequencyAt(5300, 0), $frequencyAt(5600, 20),
        $frequencyAt(5900, 25), $frequencyAt(6200, 0),
    ],
    'degree_minutes' => [
        ['time' => $cycleStart + 5600, 'value' => -180],
        ['time' => $cycleStart + 5900, 'value' => -120],
        ['time' => $cycleStart + 6200, 'value' => -100],
    ],
    'supply' => [
        ['time' => $cycleStart + 5600, 'value' => 40],
        ['time' => $cycleStart + 5900, 'value' => 42],
    ],
    'return' => [
        ['time' => $cycleStart + 5600, 'value' => 35],
        ['time' => $cycleStart + 5900, 'value' => 36],
    ],
    'hot_water' => [
        ['time' => $cycleStart + 5600, 'value' => 45],
        ['time' => $cycleStart + 5900, 'value' => 47],
    ],
    'outdoor' => [['time' => $cycleStart + 5800, 'value' => 2]],
    'electric_power' => [
        ['time' => $cycleStart + 5600, 'value' => 1000],
        ['time' => $cycleStart + 5900, 'value' => 1000],
        ['time' => $cycleStart + 6200, 'value' => 1000],
    ],
    'heat_power' => [
        ['time' => $cycleStart + 5600, 'value' => 4],
        ['time' => $cycleStart + 5900, 'value' => 4],
        ['time' => $cycleStart + 6200, 'value' => 4],
    ],
], ['supply' => '°C', 'return' => 'C', 'hot_water' => '°C', 'electric_power' => 'W', 'heat_power' => 'kW']);
expect_same(1, count($detectedCycles), 'frequency gaps do not turn an active compressor into an inferred stop');
expect_same(600, $detectedCycles[0]['duration_seconds'], 'only fully observed start/stop transitions form compressor cycles');
expect_same(-180, $detectedCycles[0]['degree_minutes_start'], 'cycle details include degree minutes at the start');
expect_same(25, $detectedCycles[0]['maximum_frequency'], 'cycle details include maximum compressor frequency');
expect_same(5.5, $detectedCycles[0]['average_delta_t'], 'cycle details average only nearby compatible supply/return samples');
expect_same(0.1667, round($detectedCycles[0]['electricity_kwh'], 4), 'cycle electricity integrates only observed short-gap power intervals');
expect_same(0.6667, round($detectedCycles[0]['heat_kwh'], 4), 'cycle heat integrates only observed short-gap power intervals');
expect_same(4.0, $detectedCycles[0]['cop'], 'cycle COP is the ratio of measured heat and electricity energy');
expect_same('Verwarming + Warm water', $detectedCycles[0]['operation'], 'cycle operation can indicate combined heating and hot-water production');
expect_same(true, $detectedCycles[0]['is_complete'], 'a measured stop marks a cycle as complete');
$activeFrequency = [$frequencyAt(0, 0), $frequencyAt(300, 20), $frequencyAt(600, 25)];
$activeCycles = nibe_dashboard_compressor_cycles(['frequency' => $activeFrequency], [], $cycleStart + 900);
expect_same(1, count($activeCycles), 'an observed active run is included');
expect_same(false, $activeCycles[0]['is_complete'], 'an active cycle is provisional');
expect_same($cycleStart + 900, $activeCycles[0]['end'], 'an active cycle ends at the supplied current time');
expect_same(600, $activeCycles[0]['duration_seconds'], 'active duration extends to the current time');
expect_same(25, $activeCycles[0]['maximum_frequency'], 'active cycle details use observed frequency values');
expect_same($cycleStart + 1200, nibe_dashboard_compressor_cycles(
    ['frequency' => $activeFrequency], [], $cycleStart + 1200
)[0]['end'], 'the provisional end advances with time');
expect_same(false, nibe_dashboard_compressor_cycles(
    ['frequency' => $activeFrequency], [], $cycleStart + 600
)[0]['is_complete'], 'a cycle can start at the current timestamp');
expect_same([], nibe_dashboard_compressor_cycles(
    ['frequency' => $activeFrequency], [], $cycleStart + 4201
), 'stale frequency data does not imply the compressor is still running');
expect_same([], nibe_dashboard_compressor_cycles(
    ['frequency' => [$frequencyAt(0, 20), $frequencyAt(300, 25)]], [], $cycleStart + 600
), 'an active run without an observed start is not fabricated');
$finishedCycles = nibe_dashboard_compressor_cycles(
    ['frequency' => [...$activeFrequency, $frequencyAt(900, 0)]], [], $cycleStart + 1200
);
expect_same(1, count($finishedCycles), 'a measured stop replaces the provisional cycle');
expect_same(true, $finishedCycles[0]['is_complete'], 'a measured stop completes the active cycle');
expect_same($cycleStart + 900, $finishedCycles[0]['end'], 'a completed cycle retains its observed end');
$coolingCycle = nibe_dashboard_compressor_cycles([
    'frequency' => [
        ['time' => $cycleStart, 'value' => 0],
        ['time' => $cycleStart + 300, 'value' => 20],
        ['time' => $cycleStart + 600, 'value' => 0],
    ],
    'supply' => [
        ['time' => $cycleStart + 300, 'value' => 5],
        ['time' => $cycleStart + 450, 'value' => 5],
    ],
    'return' => [
        ['time' => $cycleStart + 300, 'value' => 8],
        ['time' => $cycleStart + 450, 'value' => 8],
    ],
], ['supply' => '°C', 'return' => '°C']);
expect_same('Koeling', $coolingCycle[0]['operation'], 'negative cycle ΔT indicates cooling');
expect_same(null, $coolingCycle[0]['cop'], 'cycle COP is unavailable without measured cycle energy');
$unorderedReturns = nibe_dashboard_compressor_cycles([
    'frequency' => [$frequencyAt(0, 0), $frequencyAt(300, 20), $frequencyAt(900, 0)],
    'supply' => [$frequencyAt(600, 40), $frequencyAt(700, 42)],
    'return' => [$frequencyAt(700, 30), $frequencyAt(500, 35)],
], ['supply' => '°C', 'return' => '°C'], $cycleStart + 900);
expect_same(8.5, $unorderedReturns[0]['average_delta_t'], 'nearest return matching preserves input-order ties and uses each sample once');
$outdoorCop = nibe_dashboard_cop_by_outdoor_temperature([
    'electric_power' => [
        ['time' => $cycleStart, 'value' => 1],
        ['time' => $cycleStart + 300, 'value' => 1],
        ['time' => $cycleStart + 600, 'value' => 1],
        ['time' => $cycleStart + 900, 'value' => 1],
    ],
    'heat_power' => [
        ['time' => $cycleStart, 'value' => 4],
        ['time' => $cycleStart + 300, 'value' => 4],
        ['time' => $cycleStart + 600, 'value' => 4],
        ['time' => $cycleStart + 900, 'value' => 4],
    ],
    'outdoor' => [
        ['time' => $cycleStart, 'value' => -6],
        ['time' => $cycleStart + 300, 'value' => -6],
        ['time' => $cycleStart + 600, 'value' => -6],
        ['time' => $cycleStart + 900, 'value' => -6],
    ],
], ['electric_power' => 'kW', 'heat_power' => 'kW']);
expect_same(true, $outdoorCop['< -5 °C']['available'], 'outdoor COP requires a minimum number of matched points and coverage');
expect_same(4.0, $outdoorCop['< -5 °C']['cop'], 'outdoor temperature bands calculate COP from measured power energy');
expect_same(15, $outdoorCop['< -5 °C']['coverage_minutes'], 'outdoor temperature buckets expose actually integrated coverage');

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
    'evaporator' => '44363',
    'degree_minutes' => '40941',
    'frequency' => '44701',
    'calculated_supply' => '43009',
    'supply' => '44058',
    'return' => '44055',
    'electric_power' => '32486',
    'auxiliary_heat_power' => '43084',
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
expect_same('Temperatuur verdamper', nibe_dashboard_point_name([
    'source_device_id' => $deviceId,
    'source_point_id' => '44363',
    'name' => 'Evaporator (EB101-BT16)',
]), 'the evaporator point uses a friendly name');
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
expect_same(['auxiliary_heat_power' => '702'], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '702', 'name' => 'Additional heat power', 'unit' => 'kW'],
]), 'additional heat power maps by its unique name and compatible unit');
expect_same([], nibe_dashboard_default_role_mapping([
    ['measurement_point_id' => '702', 'name' => 'Additional heat power', 'unit' => 'kW'],
    ['measurement_point_id' => '703', 'name' => 'Additional heat power', 'unit' => 'W'],
]), 'ambiguous auxiliary heat points require explicit configuration');
expect_same('703', nibe_dashboard_role_mapping([
    ['measurement_point_id' => '702', 'name' => 'Additional heat power', 'unit' => 'kW'],
    ['measurement_point_id' => '703', 'name' => 'Additional heat power', 'unit' => 'W'],
], '{"auxiliary_heat_power":"703"}')['auxiliary_heat_power'], 'explicit auxiliary heat mapping resolves ambiguity');

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
    'frequency' => complete_frequency_history([
        ['raw_value' => '0', 'source_timestamp' => '2025-12-26 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '25', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '55', 'source_timestamp' => '2026-01-02 11:40:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 12:00:00'],
    ], '2025-12-26 11:50:00', '2026-01-02 12:00:00'),
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
    'defrost' => complete_frequency_history([
        ['raw_value' => '0', 'source_timestamp' => '2025-12-03 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2025-12-26 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-01 11:00:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:15:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:25:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:30:00'],
        ['raw_value' => '1', 'source_timestamp' => '2026-01-02 11:35:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:40:00'],
    ], '2025-12-26 11:00:00', '2026-01-02 12:00:00'),
    'supply' => [['raw_value' => '40', 'source_timestamp' => '2026-01-02 11:58:00']],
    'return' => [['raw_value' => '35', 'source_timestamp' => '2026-01-02 11:59:00']],
    'hot_water' => [
        ['raw_value' => '50', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '48', 'source_timestamp' => '2026-01-02 11:30:00'],
    ],
    'auxiliary_heat_power' => [['raw_value' => '1000', 'source_timestamp' => '2026-01-02 11:36:00']],
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
$counterPeriods = nibe_dashboard_performance_metrics([
    'electric_energy' => [
        ['raw_value' => '50', 'source_timestamp' => '2025-12-03 11:00:00'],
        ['raw_value' => '90', 'source_timestamp' => '2025-12-26 11:00:00'],
        ['raw_value' => '100', 'source_timestamp' => '2025-12-31 12:00:00'],
        ['raw_value' => '110', 'source_timestamp' => '2026-01-01 11:00:00'],
        ['raw_value' => '111', 'source_timestamp' => '2026-01-01 23:00:00'],
        ['raw_value' => '112', 'source_timestamp' => '2026-01-02 11:00:00'],
    ],
    'heat_energy' => [
        ['raw_value' => '100', 'source_timestamp' => '2025-12-03 11:00:00'],
        ['raw_value' => '180', 'source_timestamp' => '2025-12-26 11:00:00'],
        ['raw_value' => '200', 'source_timestamp' => '2025-12-31 12:00:00'],
        ['raw_value' => '220', 'source_timestamp' => '2026-01-01 11:00:00'],
        ['raw_value' => '222', 'source_timestamp' => '2026-01-01 23:00:00'],
        ['raw_value' => '224', 'source_timestamp' => '2026-01-02 11:00:00'],
    ],
], ['electric_energy' => 'kWh', 'heat_energy' => 'kWh'],
    new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(2.0, $counterPeriods['periods']['24h']['electricity'], '24-hour counter totals use their own boundary and baseline');
expect_same(22.0, $counterPeriods['periods']['7d']['electricity'], 'seven-day counter totals use their own boundary and baseline');
expect_same(62.0, $counterPeriods['periods']['30d']['electricity'], '30-day counter totals use their own boundary and baseline');
expect_same(false, nibe_dashboard_performance_metrics([
    'frequency' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:00:00'],
        ['raw_value' => '20', 'source_timestamp' => '2026-01-02 11:05:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:10:00'],
    ],
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')))
    ['compressor_periods']['24h']['complete'], 'sparse frequency samples do not imply a complete 24-hour period');
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
expect_same(1800, $performance['compressor_periods']['24h']['total_runtime_seconds'], 'total compressor runtime sums completed cycles');
expect_same(2, $performance['compressor_periods']['24h']['runs_under_30_minutes'], 'short-run counts use completed 30-minute threshold');
expect_same(2, $performance['compressor_periods']['24h']['runs_under_60_minutes'], 'short-run counts use completed 60-minute threshold');
$withActiveCycle = nibe_dashboard_performance_metrics([
    'frequency' => complete_frequency_history([
        ['raw_value' => '20', 'source_timestamp' => '2026-01-02 11:10:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:20:00'],
        ['raw_value' => '25', 'source_timestamp' => '2026-01-02 11:55:00'],
    ], '2026-01-01 11:55:00', '2026-01-02 12:00:00'),
], [], $now);
expect_same(2, count($withActiveCycle['cycles']), 'performance output includes completed and active cycles');
expect_same(false, $withActiveCycle['cycles'][1]['is_complete'], 'performance output marks the current cycle');
expect_same($now->getTimestamp(), $withActiveCycle['cycles'][1]['end'], 'performance uses the supplied current time');
expect_same(600, $withActiveCycle['compressor_periods']['24h']['average_run_seconds'], 'active cycles do not affect completed-run averages');
expect_same(1, $withActiveCycle['compressor_periods']['24h']['runs_under_30_minutes'], 'active cycles do not affect completed short-run counts');
$longFrequency = [['raw_value' => '0', 'source_timestamp' => gmdate('Y-m-d H:i:s', $now->getTimestamp() - 31 * 86400 - 300)]];
for ($time = $now->getTimestamp() - 31 * 86400; $time <= $now->getTimestamp(); $time += 3600) {
    $longFrequency[] = ['raw_value' => '20', 'source_timestamp' => gmdate('Y-m-d H:i:s', $time)];
}
$longActive = nibe_dashboard_performance_metrics(['frequency' => $longFrequency], [], $now);
expect_same(1, count($longActive['cycles']), 'an active cycle remains available even if it started before the 30-day window');
expect_same(false, $longActive['cycles'][0]['is_complete'], 'a long active cycle remains provisional');
expect_same(1800, $performance['compressor_periods']['24h']['average_interval_seconds'], 'average start interval uses starts within the selected period');
expect_same(true, $performance['compressor_periods']['7d']['complete'], 'seven-day compressor summary requires sufficient observed coverage');
expect_same(true, $performance['compressor_comparisons']['24h']['previous']['complete'],
    'previous-period comparisons require measured coverage too');
expect_same(2, $performance['compressor_comparisons']['24h']['changes']['starts'],
    'compressor-start differences compare equal-length covered periods');
expect_same(0.7, round($performance['frequency_distribution']['20-30'], 1), 'frequency band percentages use total observed time');
expect_same(97.9, round($performance['frequency_distribution']['0'], 1), 'the zero-hertz band includes observed compressor stops');
expect_same(84600, $performance['frequency_seconds']['0'], 'frequency bands expose total time per band');
$allFrequencyBands = nibe_dashboard_performance_metrics([
    'frequency' => complete_frequency_history([
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
    ], '2026-01-01 11:55:00', '2026-01-02 12:00:00'),
], [], new DateTimeImmutable('2026-01-02 12:00:00', new DateTimeZone('UTC')));
foreach (['0', '1-20', '20-30', '30-40', '40-50', '50-60', '60+'] as $band) {
    expect_same(true, $allFrequencyBands['frequency_seconds'][$band] > 0, 'frequency band ' . $band . ' accumulates time');
}
expect_same(2, $performance['defrost_periods']['24h']['count'], 'defrost transitions are counted');
expect_same(['heating' => 0, 'hot_water' => 1, 'auxiliary' => 1, 'unknown' => 0],
    $performance['defrost_periods']['24h']['heat_sources'], 'defrost cycles are attributed to a heat source');
expect_same(900, $performance['defrost_periods']['24h']['seconds'], 'defrost time is measured from observed samples');
expect_same(450, $performance['defrost_periods']['24h']['average_duration_seconds'], 'average duration uses completed defrost cycles');
expect_same(600, $performance['defrost_periods']['24h']['longest_duration_seconds'], 'longest defrost uses completed cycles only');
expect_same(1500, $performance['defrost_periods']['24h']['since_last_seconds'], 'time since defrost uses the latest observed start');
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

$sparseDefrostObservations = [
    strtotime('2026-12-26 11:55:00 UTC') => [
        'raw_value' => '0',
        'source_timestamp' => '2026-12-26 11:55:00',
    ],
];
foreach ([
    ['2026-12-26 12:00:00', '2026-12-27 12:00:00'],
    ['2027-01-01 11:55:00', '2027-01-01 17:15:00'],
    ['2027-01-02 06:00:00', '2027-01-02 12:00:00'],
] as [$start, $end]) {
    for ($time = strtotime($start . ' UTC'), $last = strtotime($end . ' UTC'); $time <= $last; $time += 300) {
        $timestamp = gmdate('Y-m-d H:i:s', $time);
        $sparseDefrostObservations[$time] = ['raw_value' => '0', 'source_timestamp' => $timestamp];
    }
}
foreach ([
    '2027-01-02 10:00:00' => '1',
    '2027-01-02 10:10:00' => '0',
    '2027-01-02 11:00:00' => '1',
    '2027-01-02 11:10:00' => '0',
] as $timestamp => $value) {
    $sparseDefrostObservations[strtotime($timestamp . ' UTC')] = [
        'raw_value' => $value,
        'source_timestamp' => $timestamp,
    ];
}
ksort($sparseDefrostObservations);
$sparseDefrost = nibe_dashboard_performance_metrics([
    'defrost' => array_values($sparseDefrostObservations),
], [], new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));
expect_same(46.9, round($sparseDefrost['defrost_periods']['24h']['coverage_percentage'], 1),
    'defrost coverage exposes the observed portion of a partially covered period');
expect_same(21.0, round($sparseDefrost['defrost_periods']['7d']['coverage_percentage'], 1),
    'seven-day defrost coverage remains below the 80% availability threshold');
expect_same(2, $sparseDefrost['defrost_periods']['24h']['count'],
    'observed defrosts remain available below 80% coverage');
expect_same(2, $sparseDefrost['defrost_periods']['7d']['count'],
    'seven-day defrosts remain available below 80% coverage');
expect_same(600, $sparseDefrost['defrost_periods']['24h']['seconds'],
    'defrost duration only includes observed short-gap intervals');
expect_same(true, in_array([
    'ok' => true,
    'message' => 'Datadekking: 46,9% (informatief; intervallen van maximaal 1 uur tellen mee)',
], $sparseDefrost['debug']['defrost']['24h']['count'], true),
    'defrost coverage is diagnostic rather than an availability requirement');

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

$heatingSamples = [];
foreach ([-1200, -900, -600, -300] as $offset) {
    $time = $debugNow->getTimestamp() + $offset;
    $heatingSamples['heat_power'][] = ['time' => $time, 'value' => 4.0];
    $heatingSamples['electric_power'][] = ['time' => $time, 'value' => 1000.0];
    $heatingSamples['outdoor'][] = ['time' => $time, 'value' => 0.0];
}
$heatingAnalysis = nibe_dashboard_heating_analysis(
    $heatingSamples,
    ['heat_power' => 'kW', 'electric_power' => 'W', 'outdoor' => '°C'],
    ['24h' => $debugNow->getTimestamp() - 86400, '7d' => $debugNow->getTimestamp() - 7 * 86400,
        '30d' => $debugNow->getTimestamp() - 30 * 86400],
    $debugNow->getTimestamp()
);
expect_same(0.2083, round($heatingAnalysis['periods']['24h']['degree_days'], 4),
    'degree days use the 20 °C base and only matched observed intervals');
expect_same(1.0, round($heatingAnalysis['periods']['24h']['heat_kwh'], 4),
    'heating analysis integrates thermal power over actual interval durations');
expect_same(0.25, round($heatingAnalysis['periods']['24h']['electricity_kwh'], 4),
    'heating analysis pairs measured electrical and thermal power');
expect_same(1.2, round($heatingAnalysis['periods']['24h']['electricity_per_degree_day'], 1),
    'electricity is normalized by degree days');
expect_same(4.8, round($heatingAnalysis['periods']['24h']['heat_per_degree_day'], 1),
    'heat production is normalized by degree days');
expect_same(200.0, round($heatingAnalysis['periods']['24h']['heat_loss_w_per_k'], 1),
    'heat-loss coefficient follows heat energy divided by degree-hours');
expect_same(4.0, round($heatingAnalysis['periods']['24h']['heat_demand_at_zero_kw'], 1),
    'heat demand at zero degrees uses the 20 °C indoor base');
expect_same(4.0, $heatingAnalysis['periods']['24h']['average_cop'],
    'normalized analysis reports average COP from paired energy');
expect_same(1.0, round(array_sum(array_column($heatingAnalysis['daily'], 'heat_kwh')), 4),
    'daily trend buckets are not duplicated across summary periods');
expect_same('Uitstekende prestatie', $heatingAnalysis['periods']['24h']['benchmark'],
    'benchmark uses the specified normalized electricity thresholds');
$warmSamples = $heatingSamples;
foreach ($warmSamples['outdoor'] as &$outdoorSample) {
    $outdoorSample['value'] = 25.0;
}
unset($outdoorSample);
expect_same(null, nibe_dashboard_heating_analysis(
    $warmSamples,
    ['heat_power' => 'kW', 'electric_power' => 'W', 'outdoor' => '°C'],
    ['24h' => $debugNow->getTimestamp() - 86400, '7d' => $debugNow->getTimestamp() - 7 * 86400,
        '30d' => $debugNow->getTimestamp() - 30 * 86400],
    $debugNow->getTimestamp()
)['periods']['24h']['electricity_per_degree_day'], 'temperatures above the base add no degree days');
$longGapSamples = ['heat_power' => [], 'electric_power' => [], 'outdoor' => []];
foreach ([-1800, -600, -300] as $offset) {
    $time = $debugNow->getTimestamp() + $offset;
    $longGapSamples['heat_power'][] = ['time' => $time, 'value' => 4.0];
    $longGapSamples['electric_power'][] = ['time' => $time, 'value' => 1000.0];
    $longGapSamples['outdoor'][] = ['time' => $time, 'value' => 0.0];
}
expect_same(null, nibe_dashboard_heating_analysis(
    $longGapSamples,
    ['heat_power' => 'kW', 'electric_power' => 'W', 'outdoor' => '°C'],
    ['24h' => $debugNow->getTimestamp() - 86400, '7d' => $debugNow->getTimestamp() - 7 * 86400,
        '30d' => $debugNow->getTimestamp() - 30 * 86400],
    $debugNow->getTimestamp()
)['periods']['24h']['electricity_per_degree_day'], 'long measurement gaps are not bridged');
expect_same(null, nibe_dashboard_heating_analysis(
    $heatingSamples,
    ['heat_power' => 'kW', 'electric_power' => 'W', 'outdoor' => 'F'],
    ['24h' => $debugNow->getTimestamp() - 86400, '7d' => $debugNow->getTimestamp() - 7 * 86400,
        '30d' => $debugNow->getTimestamp() - 30 * 86400],
    $debugNow->getTimestamp()
)['periods']['24h']['heat_loss_w_per_k'], 'unsupported outdoor-temperature units leave the analysis unavailable');

$auxiliaryHeat = nibe_dashboard_performance_metrics([
    'auxiliary_heat_power' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:40:00'],
        ['raw_value' => '3', 'source_timestamp' => '2026-01-02 11:45:00'],
        ['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:50:00'],
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 11:55:00'],
    ],
], ['auxiliary_heat_power' => 'kW'], $debugNow);
expect_same(0.0, $auxiliaryHeat['auxiliary_heat_power_now'], 'zero is a valid current auxiliary heating power');
expect_same(1, $auxiliaryHeat['auxiliary_heat_periods']['24h']['starts'], 'auxiliary heating counts observed zero-to-positive transitions');
expect_same(0.4167, round($auxiliaryHeat['auxiliary_heat_periods']['24h']['electricity_kwh'], 4),
    'auxiliary heating energy converts kW samples using the five-minute integration convention');
expect_same('2026-01-02 11:55:00', $auxiliaryHeat['auxiliary_heat_power_timestamp'],
    'current auxiliary heating power exposes the source time');
$auxiliaryHeatGap = nibe_dashboard_performance_metrics([
    'auxiliary_heat_power' => [
        ['raw_value' => '0', 'source_timestamp' => '2026-01-02 10:45:00'],
        ['raw_value' => '2', 'source_timestamp' => '2026-01-02 11:50:01'],
    ],
], ['auxiliary_heat_power' => 'kW'], $debugNow);
expect_same(null, $auxiliaryHeatGap['auxiliary_heat_periods']['24h']['starts'],
    'auxiliary heating does not infer a start across a gap longer than one hour');

$emptyDebug = nibe_dashboard_performance_metrics([], [], $debugNow);
expect_same(null, $emptyDebug['auxiliary_heat_power_now'], 'unmapped auxiliary heating power is unavailable');
expect_same(true, in_array(['ok' => false, 'message' => 'Elektrisch bijverwarmingsvermogen gekoppeld'],
    $emptyDebug['debug']['auxiliary_heat']['power_now'], true), 'unavailable auxiliary heat explains missing mapping');
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
