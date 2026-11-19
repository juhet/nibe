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

$request = nibe_dashboard_history_request(['points' => '1,22', 'start' => '100', 'end' => '200']);
expect_same(['points' => ['1', '22'], 'start' => 100, 'end' => 200], $request, 'valid history request');
foreach ([
    ['points' => '1,1', 'start' => '100', 'end' => '200'],
    ['points' => '1,2,3,4,5,6,7', 'start' => '100', 'end' => '200'],
    ['points' => '1 OR 1=1', 'start' => '100', 'end' => '200'],
    ['points' => '1', 'start' => '100', 'end' => '2592101'],
    ['points' => '1', 'start' => '200', 'end' => '100'],
    ['points' => ['1'], 'start' => '100', 'end' => '200'],
] as $invalid) {
    expect_same(null, nibe_dashboard_history_request($invalid), 'invalid history request');
}

echo "Dashboard tests passed.\n";
