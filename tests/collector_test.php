<?php

require_once __DIR__ . '/../app/collector.php';

final class CollectorSchemaTestPDO extends PDO
{
    public ?string $statement = null;

    public function __construct()
    {
    }

    public function exec(string $statement): int|false
    {
        $this->statement = $statement;
        return 0;
    }
}

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

expect_same('0', nibe_raw_value(0), 'zero is retained');
expect_same('0.0', nibe_raw_value(0.0), 'decimal zero is retained');
expect_same('false', nibe_raw_value(false), 'false is retained distinctly');
expect_same('12.5', nibe_raw_value('12.5'), 'string values are unchanged');
expect_same(
    true,
    nibe_collector_authorized('collector-test-token', 'Bearer ' . 'collector-test-token'),
    'bearer token is accepted'
);
expect_same(
    true,
    nibe_collector_authorized('collector-test-token', 'Basic ' . base64_encode('collector:collector-test-token')),
    'browser basic authentication is accepted'
);
expect_same(
    true,
    nibe_collector_authorized('collector-test-token', '', 'collector', 'collector-test-token'),
    'PHP basic authentication variables are accepted'
);
expect_same(
    false,
    nibe_collector_authorized('collector-test-token', 'Basic ' . base64_encode('other:collector-test-token')),
    'basic authentication requires the collector username'
);
expect_same(
    false,
    nibe_collector_authorized('collector-test-token', 'Bearer ' . 'wrong-token'),
    'incorrect collector token is rejected'
);
expect_same(
    false,
    nibe_collector_authorized(null, 'Bearer ' . 'collector-test-token'),
    'missing configured token is rejected'
);
expect_same(null, nibe_source_timestamp(null), 'missing source timestamp remains missing');
expect_same(
    '2026-01-02 02:04:05.000000',
    nibe_source_timestamp('2026-01-02T03:04:05+01:00'),
    'source timestamp is converted to UTC'
);
expect_same(
    '2026-01-02 03:04:05.000000',
    nibe_source_timestamp('2026-01-02T03:04:05'),
    'timezone-less source timestamp is treated as UTC'
);
$weatherResponse = json_encode([
    'current' => [
        'time' => '2026-01-02T03:04',
        'temperature_2m' => 3.2,
        'relative_humidity_2m' => 84,
        'apparent_temperature' => 0.8,
        'precipitation' => 0.1,
        'weather_code' => 61,
        'wind_speed_10m' => 12.4,
        'is_day' => 1,
    ],
    'current_units' => [
        'temperature_2m' => '°C',
        'relative_humidity_2m' => '%',
        'apparent_temperature' => '°C',
        'precipitation' => 'mm',
        'weather_code' => 'wmo code',
        'wind_speed_10m' => 'km/h',
        'is_day' => '',
    ],
], JSON_THROW_ON_ERROR);
$weatherUrl = null;
$weather = (new NibeOpenMeteoClient(static function (string $url) use (&$weatherUrl, $weatherResponse): string {
    $weatherUrl = $url;
    return $weatherResponse;
}))->getCurrent();
parse_str((string) parse_url($weatherUrl, PHP_URL_QUERY), $weatherQuery);
expect_same('53.17', $weatherQuery['latitude'], 'weather request uses Grootegast latitude');
expect_same('6.27', $weatherQuery['longitude'], 'weather request uses Grootegast longitude');
expect_same('UTC', $weatherQuery['timezone'], 'weather observations use UTC');
expect_same('2026-01-02 03:04:00.000000', $weather['source_timestamp'], 'weather timestamp is normalized to UTC');
$weatherValues = json_decode($weather['raw_data'], true, 16, JSON_THROW_ON_ERROR);
expect_same(3.2, $weatherValues['current']['temperature_2m'], 'weather source values are retained');
try {
    (new NibeOpenMeteoClient(static fn (string $url): string => '{"current":[]}'))->getCurrent();
    throw new RuntimeException('incomplete weather response should fail');
} catch (RuntimeException $exception) {
    expect_same('Open-Meteo returned an invalid response.', $exception->getMessage(), 'incomplete weather response is rejected');
}
expect_same(
    'Collection failed due to a database or internal error.',
    nibe_collection_error(new PDOException('sensitive database details')),
    'database details are not stored as error text'
);

try {
    nibe_source_timestamp('not-a-timestamp');
    throw new RuntimeException('invalid timestamp should fail');
} catch (RuntimeException $exception) {
    expect_same(
        'myUplink returned an invalid measurement timestamp.',
        $exception->getMessage(),
        'invalid source timestamp is rejected'
    );
}

$schemaDatabase = new CollectorSchemaTestPDO();
nibe_ensure_weather_schema($schemaDatabase);
expect_same(true, str_contains((string) $schemaDatabase->statement, 'CREATE TABLE IF NOT EXISTS nibe_weather_observations'),
    'weather storage is created automatically and idempotently');

echo "Collector tests passed.\n";
