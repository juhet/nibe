<?php

function nibe_dashboard_default_role_mapping(array $measurements): array
{
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
    $mapping = [];

    foreach ($sourcePointIds as $role => $sourcePointId) {
        foreach ($measurements as $measurement) {
            if ((string) ($measurement['source_device_id'] ?? '') === $deviceId
                && (string) ($measurement['source_point_id'] ?? '') === $sourcePointId) {
                $mapping[$role] = (string) $measurement['measurement_point_id'];
                break;
            }
        }
    }

    return $mapping;
}

function nibe_dashboard_point_name(array $point): string
{
    $deviceId = 'emmy-n-20251117-06794225149019-fc-0f-e7-5e-ef-32';
    if ((string) ($point['source_device_id'] ?? '') !== $deviceId) {
        return (string) ($point['name'] ?? '');
    }

    return [
        '40033' => 'Temperatuur in huis',
        '40013' => 'Temperatuur warm tapwater',
        '44362' => 'Buitentemperatuur',
        '40941' => 'Graadminuten',
        '44701' => 'Compressorfrequentie',
        '43009' => 'Berekende toevoer',
        '44058' => 'Aanvoer',
        '44055' => 'Retour',
    ][(string) ($point['source_point_id'] ?? '')] ?? (string) ($point['name'] ?? '');
}

function nibe_dashboard_kpis(
    array $observations,
    string $supplyUnit,
    string $returnUnit,
    DateTimeImmutable $now
): array {
    $now = $now->setTimezone(new DateTimeZone('UTC'));
    $start = $now->getTimestamp() - 86400;
    $samples = [];
    foreach (['frequency', 'supply', 'return'] as $role) {
        foreach ($observations[$role] ?? [] as $observation) {
            $rawValue = $observation['raw_value'] ?? null;
            $timestamp = $observation['source_timestamp'] ?? $observation['collected_at'] ?? null;
            if (!is_string($rawValue) || !is_numeric($rawValue) || !is_string($timestamp)) {
                continue;
            }

            try {
                $time = (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
            } catch (Exception $exception) {
                continue;
            }
            $value = (float) $rawValue;
            if (!is_finite($value) || $time > $now->getTimestamp()) {
                continue;
            }
            $samples[$role][] = ['time' => $time, 'value' => $value];
        }
        $samples[$role] ??= [];
        usort($samples[$role], static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    }

    $deltaT = null;
    $normalizeUnit = static fn (string $unit): string => match (strtolower(trim($unit))) {
        'c', 'celsius', '°c' => '°c',
        default => strtolower(trim($unit)),
    };
    if ($supplyUnit !== '' && $normalizeUnit($supplyUnit) === $normalizeUnit($returnUnit)) {
        $supply = array_values(array_filter(
            $samples['supply'],
            static fn (array $sample): bool => $sample['time'] >= $start
        ));
        $return = array_values(array_filter(
            $samples['return'],
            static fn (array $sample): bool => $sample['time'] >= $start
        ));
        $usedReturns = [];
        $differences = [];
        foreach ($supply as $supplySample) {
            $nearest = null;
            $nearestDistance = 301;
            foreach ($return as $index => $returnSample) {
                if (isset($usedReturns[$index])) {
                    continue;
                }
                $distance = abs($supplySample['time'] - $returnSample['time']);
                if ($distance < $nearestDistance) {
                    $nearest = $index;
                    $nearestDistance = $distance;
                }
            }
            if ($nearest !== null && $nearestDistance <= 300) {
                $usedReturns[$nearest] = true;
                $differences[] = $supplySample['value'] - $return[$nearest]['value'];
            }
        }
        if ($differences !== []) {
            $deltaT = array_sum($differences) / count($differences);
        }
    }

    $frequencySamples = $samples['frequency'];
    $recentFrequencySamples = array_values(array_filter(
        $frequencySamples,
        static fn (array $sample): bool => $sample['time'] >= $start
    ));
    $compressorStarts = null;
    $averageRunSeconds = null;
    if (count($recentFrequencySamples) >= 2) {
        $compressorStarts = 0;
        $runs = [];
        $previousRunning = null;
        $runStart = null;
        $previousTime = null;
        foreach ($frequencySamples as $sample) {
            $running = $sample['value'] > 0;
            if ($previousTime !== null && $sample['time'] - $previousTime > 3600) {
                $previousRunning = null;
                $runStart = null;
            }
            if ($sample['time'] >= $start && $previousRunning === false && $running) {
                $compressorStarts++;
                $runStart = $sample['time'];
            } elseif ($previousRunning === true && !$running && $runStart !== null) {
                if ($sample['time'] >= $start) {
                    $runs[] = $sample['time'] - $runStart;
                }
                $runStart = null;
            }
            $previousRunning = $running;
            $previousTime = $sample['time'];
        }
        if ($runs !== []) {
            $averageRunSeconds = array_sum($runs) / count($runs);
        }
    }

    return [
        'compressor_starts' => $compressorStarts,
        'average_delta_t' => $deltaT,
        'average_run_seconds' => $averageRunSeconds,
    ];
}

function nibe_dashboard_history_request(array $query): ?array
{
    $ids = $query['points'] ?? null;
    $start = $query['start'] ?? null;
    $end = $query['end'] ?? null;
    if (!is_string($ids) || !is_string($start) || !is_string($end)
        || !preg_match('/^[0-9]{1,10}$/D', $start)
        || !preg_match('/^[0-9]{1,10}$/D', $end)) {
        return null;
    }

    $points = explode(',', $ids);
    if (count($points) < 1 || count($points) > 8) {
        return null;
    }
    foreach ($points as $point) {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $point)) {
            return null;
        }
    }
    if (count(array_unique($points)) !== count($points)
        || (int) $end <= (int) $start || (int) $end - (int) $start > 30 * 86400) {
        return null;
    }

    return ['points' => $points, 'start' => (int) $start, 'end' => (int) $end];
}

function nibe_dashboard_sparkline(array $observations, ?string $unit): ?string
{
    $points = [];
    foreach ($observations as $observation) {
        $rawValue = $observation['raw_value'] ?? null;
        $timestamp = $observation['source_timestamp'] ?? $observation['collected_at'] ?? null;
        if (!is_string($rawValue) || !is_numeric($rawValue) || !is_string($timestamp)) {
            continue;
        }

        $value = (float) $rawValue;
        if (!is_finite($value)) {
            continue;
        }

        try {
            $time = (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $exception) {
            continue;
        }

        $points[] = [
            'raw_value' => $rawValue,
            'timestamp' => $timestamp,
            'time' => $time,
            'value' => $value,
            'time_label' => array_key_exists('source_timestamp', $observation) && $observation['source_timestamp'] !== null
                ? 'Bron tijd'
                : 'Verzameld',
        ];
    }

    if ($points === []) {
        return null;
    }

    usort($points, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    $minTime = min(array_column($points, 'time'));
    $maxTime = max(array_column($points, 'time'));
    $minValue = min(array_column($points, 'value'));
    $maxValue = max(array_column($points, 'value'));
    $circles = '';

    foreach ($points as $point) {
        $x = $maxTime === $minTime ? 80 : 4 + (($point['time'] - $minTime) / ($maxTime - $minTime) * 152);
        $y = $maxValue === $minValue ? 24 : 44 - (($point['value'] - $minValue) / ($maxValue - $minValue) * 40);
        $label = $point['time_label'] . ' (UTC): ' . $point['timestamp'] . ' — ' . $point['raw_value'];
        if ($unit !== null && $unit !== '') {
            $label .= ' ' . $unit;
        }
        $circles .= sprintf(
            '<circle cx="%s" cy="%s" r="2.5"><title>%s</title></circle>',
            number_format($x, 2, '.', ''),
            number_format($y, 2, '.', ''),
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    return '<svg class="trend" viewBox="0 0 160 48" role="img" aria-label="Numerieke waarnemingen in de afgelopen 24 uur">'
        . '<g fill="#087f8c">' . $circles . '</g></svg>';
}
