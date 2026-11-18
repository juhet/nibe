<?php

require_once __DIR__ . '/../app/collector.php';

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

echo "Collector tests passed.\n";
