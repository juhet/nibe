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
        'electric_power' => ['32486', '13'],
        'heat_power' => '598',
        'defrost' => '44703',
    ];
    $mapping = [];

    foreach ($sourcePointIds as $role => $candidates) {
        foreach ((array) $candidates as $sourcePointId) {
            foreach ($measurements as $measurement) {
                if ((string) ($measurement['source_device_id'] ?? '') === $deviceId
                    && (string) ($measurement['source_point_id'] ?? '') === $sourcePointId) {
                    $mapping[$role] = (string) $measurement['measurement_point_id'];
                    break 2;
                }
            }
        }
    }

    return $mapping;
}

function nibe_dashboard_role_mapping(array $measurements, ?string $configuredRoles): array
{
    $mapping = nibe_dashboard_default_role_mapping($measurements);
    if ($configuredRoles === null || trim($configuredRoles) === '') {
        return $mapping;
    }

    try {
        $roles = json_decode($configuredRoles, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $mapping;
    }
    if (!is_array($roles)) {
        return $mapping;
    }

    $allowedRoles = ['electric_power', 'heat_power', 'electric_energy', 'heat_energy', 'defrost'];
    $availableIds = [];
    foreach ($measurements as $measurement) {
        $availableIds[(string) ($measurement['measurement_point_id'] ?? '')] = true;
    }
    foreach ($allowedRoles as $role) {
        $measurementId = $roles[$role] ?? null;
        if ((is_string($measurementId) || is_int($measurementId))
            && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $measurementId)
            && isset($availableIds[(string) $measurementId])) {
            $mapping[$role] = (string) $measurementId;
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

function nibe_dashboard_performance_metrics(array $observations, array $units, DateTimeImmutable $now): array
{
    $now = $now->setTimezone(new DateTimeZone('UTC'));
    $nowTimestamp = $now->getTimestamp();
    $windows = [
        'today' => (new DateTimeImmutable($now->format('Y-m-d 00:00:00'), new DateTimeZone('UTC')))->getTimestamp(),
        '24h' => $nowTimestamp - 86400,
        '7d' => $nowTimestamp - 7 * 86400,
        '30d' => $nowTimestamp - 30 * 86400,
    ];
    $samples = [];
    foreach (['frequency', 'supply', 'return', 'calculated_supply', 'outdoor', 'degree_minutes',
        'electric_power', 'heat_power', 'electric_energy', 'heat_energy', 'defrost'] as $role) {
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
            if (!is_finite($value) || $time > $nowTimestamp) {
                continue;
            }
            $samples[$role][] = ['time' => $time, 'value' => $value];
        }
        $samples[$role] ??= [];
        usort($samples[$role], static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    }

    $emptyPeriods = [];
    foreach ($windows as $period => $_start) {
        $emptyPeriods[$period] = ['cop' => null, 'electricity' => null, 'heat' => null, 'average_delta_t' => null];
    }
    foreach (['electric_energy' => 'electricity', 'heat_energy' => 'heat'] as $role => $metric) {
        $unit = strtolower(trim((string) ($units[$role] ?? '')));
        $factor = match ($unit) {
            'kwh' => 1.0,
            'wh' => 0.001,
            default => null,
        };
        foreach (['today', '24h', '7d', '30d'] as $period) {
            $start = $windows[$period];
            $inPeriod = array_values(array_filter(
                $samples[$role],
                static fn (array $sample): bool => $sample['time'] >= $start
            ));
            $baseline = null;
            foreach ($samples[$role] as $sample) {
                if ($sample['time'] < $start && ($baseline === null || $sample['time'] > $baseline['time'])) {
                    $baseline = $sample;
                }
            }
            if ($factor !== null && $baseline !== null && $inPeriod !== []
                && $start - $baseline['time'] <= 21600
                && $nowTimestamp - $inPeriod[count($inPeriod) - 1]['time'] <= 21600) {
                $delta = $inPeriod[count($inPeriod) - 1]['value'] - $baseline['value'];
                if ($delta >= 0) {
                    $emptyPeriods[$period][$metric] = $delta * $factor;
                }
            }
        }
    }

    $powerToKw = static function (float $value, string $unit): ?float {
        return match (strtolower(trim($unit))) {
            'w', 'watts' => $value / 1000,
            'kw', 'kilowatts' => $value,
            default => null,
        };
    };
    $integratedPower = [];
    foreach ($windows as $period => $_start) {
        $integratedPower[$period] = ['electricity' => null, 'heat' => null];
    }
    foreach (['electric_power' => 'electricity', 'heat_power' => 'heat'] as $role => $metric) {
        $unit = strtolower(trim((string) ($units[$role] ?? '')));
        if (!in_array($unit, ['w', 'watts', 'kw', 'kilowatts'], true)) {
            continue;
        }
        foreach ($windows as $period => $start) {
            $periodSamples = array_values(array_filter(
                $samples[$role],
                static fn (array $sample): bool => $sample['time'] >= $start
            ));
            if ($periodSamples === []) {
                continue;
            }
            $integratedPower[$period][$metric] = array_sum(array_map(
                static fn (array $sample): float => max(0, $powerToKw($sample['value'], $unit) ?? 0) * 5 / 60,
                $periodSamples
            ));
            $emptyPeriods[$period][$metric] = $integratedPower[$period][$metric];
        }
    }
    foreach ($integratedPower as $period => $energy) {
        if ($energy['electricity'] !== null && $energy['electricity'] > 0 && $energy['heat'] !== null) {
            $emptyPeriods[$period]['cop'] = $energy['heat'] / $energy['electricity'];
        }
    }
    foreach ($windows as $period => $start) {
        $supply = array_values(array_filter($samples['supply'], static fn (array $sample): bool => $sample['time'] >= $start));
        $return = array_values(array_filter($samples['return'], static fn (array $sample): bool => $sample['time'] >= $start));
        $supplyUnit = strtolower(trim((string) ($units['supply'] ?? '')));
        $returnUnit = strtolower(trim((string) ($units['return'] ?? '')));
        if (in_array($supplyUnit, ['c', 'celsius', '°c'], true)) {
            $supplyUnit = '°c';
        }
        if (in_array($returnUnit, ['c', 'celsius', '°c'], true)) {
            $returnUnit = '°c';
        }
        if ($supplyUnit !== '' && $supplyUnit === $returnUnit
            && $supply !== [] && $return !== []) {
            $differences = [];
            $usedReturns = [];
            foreach ($supply as $sample) {
                $nearest = null;
                $distance = 301;
                foreach ($return as $index => $other) {
                    if (isset($usedReturns[$index])) {
                        continue;
                    }
                    $candidateDistance = abs($sample['time'] - $other['time']);
                    if ($candidateDistance < $distance) {
                        $nearest = $index;
                        $distance = $candidateDistance;
                    }
                }
                if ($nearest !== null && $distance <= 300) {
                    $differences[] = $sample['value'] - $return[$nearest]['value'];
                    $usedReturns[$nearest] = true;
                }
            }
            if ($differences !== []) {
                $emptyPeriods[$period]['average_delta_t'] = array_sum($differences) / count($differences);
            }
        }
    }
    $deltaTNow = null;
    $supplyUnit = strtolower(trim((string) ($units['supply'] ?? '')));
    $returnUnit = strtolower(trim((string) ($units['return'] ?? '')));
    if (in_array($supplyUnit, ['c', 'celsius', '°c'], true)) {
        $supplyUnit = '°c';
    }
    if (in_array($returnUnit, ['c', 'celsius', '°c'], true)) {
        $returnUnit = '°c';
    }
    if ($supplyUnit !== '' && $supplyUnit === $returnUnit) {
        $supply = $samples['supply'] === [] ? null : end($samples['supply']);
        $return = $samples['return'] === [] ? null : end($samples['return']);
        if ($supply !== null && $return !== null && abs($supply['time'] - $return['time']) <= 300
            && $nowTimestamp - max($supply['time'], $return['time']) <= 900) {
            $deltaTNow = $supply['value'] - $return['value'];
        }
    }

    $runs = [];
    $starts = ['24h' => 0, '7d' => 0];
    $runStart = null;
    $lastFrequency = null;
    $frequencySeconds = [
        '0' => 0,
        '1-20' => 0,
        '20-30' => 0,
        '30-40' => 0,
        '40-50' => 0,
        '50-60' => 0,
        '60+' => 0,
    ];
    $observedFrequencySeconds = 0;
    foreach ($samples['frequency'] as $index => $sample) {
        $next = $samples['frequency'][$index + 1] ?? null;
        if ($next === null || $next['time'] - $sample['time'] <= 0 || $next['time'] - $sample['time'] > 3600) {
            if ($sample['value'] <= 0 && $runStart !== null && $sample['time'] >= $windows['24h']) {
                $runs[] = $sample['time'] - $runStart;
            }
            $runStart = null;
            $lastFrequency = null;
            continue;
        }
        $running = $sample['value'] > 0;
        if ($running && $lastFrequency === false) {
            foreach (['24h', '7d'] as $period) {
                if ($sample['time'] >= $windows[$period]) {
                    $starts[$period]++;
                }
            }
        }
        if ($sample['time'] >= $windows['24h'] && $running && $lastFrequency === false) {
            $runStart = $sample['time'];
        } elseif ($sample['time'] < $windows['24h'] && $running && $lastFrequency === false) {
            $runStart = $sample['time'];
        }
        if (!$running && $lastFrequency === true && $runStart !== null) {
            if ($sample['time'] >= $windows['24h']) {
                $runs[] = $sample['time'] - $runStart;
            }
            $runStart = null;
        }
        if ($next['time'] > $windows['24h']) {
            $segmentStart = max($sample['time'], $windows['24h']);
            $duration = max(0, min($next['time'], $nowTimestamp) - $segmentStart);
            $band = $sample['value'] < 0 ? null : ($sample['value'] === 0.0 ? '0' : match (true) {
                $sample['value'] >= 60 => '60+',
                $sample['value'] >= 50 => '50-60',
                $sample['value'] >= 40 => '40-50',
                $sample['value'] >= 30 => '30-40',
                $sample['value'] >= 20 => '20-30',
                $sample['value'] > 0 => '1-20',
                default => null,
            });
            if ($band !== null) {
                $observedFrequencySeconds += $duration;
                $frequencySeconds[$band] += $duration;
            }
        }
        $lastFrequency = $running;
    }
    $runLengths = array_values(array_filter($runs, static fn (int $duration): bool => $duration > 0));

    $frequencyWindowValid = [];
    foreach (['24h', '7d'] as $period) {
        $frequencyBaseline = null;
        foreach ($samples['frequency'] as $sample) {
            if ($sample['time'] < $windows[$period]
                && ($frequencyBaseline === null || $sample['time'] > $frequencyBaseline['time'])) {
                $frequencyBaseline = $sample;
            }
        }
        $periodSamples = array_filter(
            $samples['frequency'],
            static fn (array $sample): bool => $sample['time'] >= $windows[$period]
        );
        $latestFrequency = $samples['frequency'] === [] ? null : end($samples['frequency']);
        $frequencyWindowValid[$period] = count($periodSamples) >= 2
            && $frequencyBaseline !== null
            && $windows[$period] - $frequencyBaseline['time'] <= 21600
            && $latestFrequency !== null
            && $nowTimestamp - $latestFrequency['time'] <= 21600;
    }
    $defrostPeriods = [];
    foreach (['24h', '7d', '30d'] as $period) {
        $start = $windows[$period];
        $defrostStarts = 0;
        $defrostStartTimes = [];
        $defrostSeconds = 0;
        $defrostDurations = [];
        $activeDefrostStart = null;
        $activeDefrostSeconds = 0;
        $periodRunningSeconds = 0;
        $previousDefrost = null;
        $defrostBaseline = null;
        $latestDefrost = $samples['defrost'] === [] ? null : end($samples['defrost']);
        foreach ($samples['defrost'] as $sample) {
            if ($sample['time'] < $start
                && ($defrostBaseline === null || $sample['time'] > $defrostBaseline['time'])) {
                $defrostBaseline = $sample;
            }
        }
        foreach ($samples['defrost'] as $index => $sample) {
            $next = $samples['defrost'][$index + 1] ?? null;
            if ($next === null || $next['time'] <= $sample['time'] || $next['time'] - $sample['time'] > 3600) {
                $previousDefrost = null;
                $activeDefrostStart = null;
                $activeDefrostSeconds = 0;
                continue;
            }
            $active = $sample['value'] > 0;
            if ($sample['time'] >= $start && $active && $previousDefrost === false) {
                $defrostStarts++;
                $defrostStartTimes[] = $sample['time'];
            }
            if ($active && $previousDefrost === false) {
                $activeDefrostStart = $sample['time'];
                $activeDefrostSeconds = 0;
            }
            if ($active && $next['time'] > $start) {
                $defrostSeconds += max(0, min($next['time'], $nowTimestamp) - max($sample['time'], $start));
            }
            if ($active && $activeDefrostStart !== null) {
                $activeDefrostSeconds += $next['time'] - $sample['time'];
                if ($next['value'] <= 0) {
                    if ($activeDefrostStart >= $start) {
                        $defrostDurations[] = $activeDefrostSeconds;
                    }
                    $activeDefrostStart = null;
                    $activeDefrostSeconds = 0;
                }
            }
            $previousDefrost = $active;
        }
        foreach ($samples['frequency'] as $index => $sample) {
            $next = $samples['frequency'][$index + 1] ?? null;
            if ($next !== null && $sample['value'] > 0 && $next['time'] > $sample['time']
                && $next['time'] - $sample['time'] <= 3600 && $next['time'] > $start) {
                $periodRunningSeconds += max(0, min($next['time'], $nowTimestamp) - max($sample['time'], $start));
            }
        }
        $hasPeriodData = count(array_filter(
            $samples['defrost'],
            static fn (array $sample): bool => $sample['time'] >= $start
        )) >= 2 && $defrostBaseline !== null && $start - $defrostBaseline['time'] <= 21600
            && $latestDefrost !== null && $nowTimestamp - $latestDefrost['time'] <= 21600;
        $defrostPeriods[$period] = [
            'count' => $hasPeriodData ? $defrostStarts : null,
            'seconds' => $hasPeriodData ? $defrostSeconds : null,
            'average_duration_seconds' => $hasPeriodData && $defrostDurations !== []
                ? array_sum($defrostDurations) / count($defrostDurations)
                : null,
            'average_interval_seconds' => $hasPeriodData && count($defrostStartTimes) >= 2
                ? (max($defrostStartTimes) - min($defrostStartTimes)) / (count($defrostStartTimes) - 1)
                : null,
            'percent' => $hasPeriodData && $periodRunningSeconds > 0 && $defrostSeconds <= $periodRunningSeconds
                ? 100 * $defrostSeconds / $periodRunningSeconds
                : null,
        ];
    }

    $latestHeatPower = $samples['heat_power'] === [] ? null : end($samples['heat_power']);
    $latestElectricPower = $samples['electric_power'] === [] ? null : end($samples['electric_power']);
    $latestCopTime = $latestHeatPower === null || $latestElectricPower === null
        ? null
        : max($latestHeatPower['time'], $latestElectricPower['time']);
    $latestHeatKw = $latestHeatPower === null ? null : $powerToKw(
        $latestHeatPower['value'],
        (string) ($units['heat_power'] ?? '')
    );
    $latestElectricKw = $latestElectricPower === null ? null : $powerToKw(
        $latestElectricPower['value'],
        (string) ($units['electric_power'] ?? '')
    );
    $latestCop = $latestHeatPower !== null && $latestElectricPower !== null
        && $nowTimestamp - $latestHeatPower['time'] <= 900
        && $nowTimestamp - $latestElectricPower['time'] <= 900
        && $latestHeatKw !== null && $latestHeatKw >= 0
        && $latestElectricKw !== null && $latestElectricKw > 0
        ? $latestHeatKw / $latestElectricKw
        : null;
    $averageCop24h = $emptyPeriods['24h']['cop'];
    $recentHeatPowerCount = count(array_filter(
        $samples['heat_power'],
        static fn (array $sample): bool => $sample['time'] >= $windows['24h']
    ));
    $recentElectricPowerCount = count(array_filter(
        $samples['electric_power'],
        static fn (array $sample): bool => $sample['time'] >= $windows['24h']
    ));
    $copWarning = $latestCop !== null && $averageCop24h !== null
        && $recentHeatPowerCount >= 3 && $recentElectricPowerCount >= 3
        && abs($latestCop - $averageCop24h) > abs($averageCop24h) * 0.2;
    $frequencyDistribution = [];
    foreach ($frequencySeconds as $band => $seconds) {
        $frequencyDistribution[$band] = $observedFrequencySeconds > 0 && $frequencyWindowValid['24h']
            ? 100 * $seconds / $observedFrequencySeconds
            : null;
    }

    return [
        'periods' => $emptyPeriods,
        'cop_now' => $latestCop,
        'cop_now_timestamp' => $latestCop !== null ? gmdate('Y-m-d H:i:s', $latestCopTime) : null,
        'cop_warning' => $copWarning,
        'delta_t_now' => $deltaTNow,
        'compressor_starts' => $frequencyWindowValid['24h'] ? $starts['24h'] : null,
        'compressor_starts_7d' => $frequencyWindowValid['7d'] ? $starts['7d'] : null,
        'average_run_seconds' => $runLengths === [] ? null : array_sum($runLengths) / count($runLengths),
        'longest_run_seconds' => $runLengths === [] ? null : max($runLengths),
        'shortest_run_seconds' => $runLengths === [] ? null : min($runLengths),
        'frequency_distribution' => $frequencyDistribution,
        'frequency_seconds' => $frequencySeconds,
        'defrost_periods' => $defrostPeriods,
    ];
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
