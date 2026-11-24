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
        'auxiliary_heat_power' => '43084',
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

    $heatPowerCandidates = array_values(array_filter($measurements, static function (array $measurement): bool {
        return strcasecmp(trim((string) ($measurement['name'] ?? '')), 'Heat capacity (EB101)') === 0
            && in_array(strtolower(trim((string) ($measurement['unit'] ?? ''))), ['w', 'watts', 'kw', 'kilowatts'], true)
            && isset($measurement['measurement_point_id']);
    }));
    if (count($heatPowerCandidates) === 1) {
        $mapping['heat_power'] = (string) $heatPowerCandidates[0]['measurement_point_id'];
    }

    $auxiliaryHeatCandidates = array_values(array_filter($measurements, static function (array $measurement): bool {
        return strcasecmp(trim((string) ($measurement['name'] ?? '')), 'Additional heat power') === 0
            && in_array(strtolower(trim((string) ($measurement['unit'] ?? ''))), ['w', 'watts', 'kw', 'kilowatts'], true)
            && isset($measurement['measurement_point_id']);
    }));
    if (!isset($mapping['auxiliary_heat_power']) && count($auxiliaryHeatCandidates) === 1) {
        $mapping['auxiliary_heat_power'] = (string) $auxiliaryHeatCandidates[0]['measurement_point_id'];
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

    $allowedRoles = [
        'electric_power', 'heat_power', 'auxiliary_heat_power', 'electric_energy', 'heat_energy', 'defrost',
    ];
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

function nibe_dashboard_measurement_freshness(?string $timestamp, DateTimeImmutable $now, int $maxAgeSeconds = 900): array
{
    if ($timestamp === null || $timestamp === '') {
        return ['status' => 'missing', 'age_seconds' => null];
    }

    try {
        $time = (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Exception $exception) {
        return ['status' => 'missing', 'age_seconds' => null];
    }

    $age = $now->getTimestamp() - $time;
    return [
        'status' => $age >= 0 && $age <= $maxAgeSeconds ? 'live' : 'stale',
        'age_seconds' => $age,
    ];
}

function nibe_dashboard_weather(?array $observation): ?array
{
    if ($observation === null || !is_string($observation['source_timestamp'] ?? null)
        || !is_string($observation['raw_data'] ?? null)) {
        return null;
    }

    try {
        $timestamp = (new DateTimeImmutable($observation['source_timestamp'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $data = json_decode($observation['raw_data'], true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($data) || !is_array($data['current'] ?? null) || !is_array($data['current_units'] ?? null)) {
        return null;
    }

    $current = $data['current'];
    $units = $data['current_units'];
    $fields = [
        'temperature_2m', 'relative_humidity_2m', 'apparent_temperature',
        'precipitation', 'weather_code', 'wind_speed_10m',
    ];
    foreach ($fields as $field) {
        if (!isset($current[$field]) || !is_numeric($current[$field])
            || !is_finite((float) $current[$field]) || !is_string($units[$field] ?? null)) {
            return null;
        }
    }

    return [
        'timestamp' => $timestamp,
        'temperature' => (float) $current['temperature_2m'],
        'temperature_unit' => $units['temperature_2m'],
        'apparent_temperature' => (float) $current['apparent_temperature'],
        'humidity' => (float) $current['relative_humidity_2m'],
        'humidity_unit' => $units['relative_humidity_2m'],
        'precipitation' => (float) $current['precipitation'],
        'precipitation_unit' => $units['precipitation'],
        'wind_speed' => (float) $current['wind_speed_10m'],
        'wind_speed_unit' => $units['wind_speed_10m'],
        'weather_code' => (int) $current['weather_code'],
        'is_day' => (int) ($current['is_day'] ?? 0) === 1,
    ];
}

function nibe_dashboard_weather_condition(int $code): string
{
    return match ($code) {
        0 => 'Onbewolkt',
        1 => 'Overwegend helder',
        2 => 'Half bewolkt',
        3 => 'Bewolkt',
        45, 48 => 'Mist',
        51, 53, 55, 56, 57 => 'Motregen',
        61, 63, 65, 66, 67 => 'Regen',
        71, 73, 75, 77 => 'Sneeuw',
        80, 81, 82 => 'Regenbuien',
        85, 86 => 'Sneeuwbuien',
        95, 96, 99 => 'Onweer',
        default => 'Weerconditie onbekend',
    };
}

function nibe_dashboard_suggestions(array $performance): array
{
    $suggestions = [];
    $compressor = $performance['compressor_periods']['24h'] ?? [];
    if (($compressor['complete'] ?? false) === true
        && is_numeric($compressor['starts'] ?? null)
        && $compressor['starts'] > 72
        && is_numeric($compressor['average_run_seconds'] ?? null)
        && $compressor['average_run_seconds'] < 2400) {
        $suggestions[] = [
            'title' => 'Controleer frequent starten van de compressor',
            'evidence' => sprintf(
                'In de afgelopen 24 uur zijn %d starts en een gemiddelde runduur van %.0f minuten gemeten; de frequentiehistorie is compleet (%s%% dekking).',
                $compressor['starts'],
                $compressor['average_run_seconds'] / 60,
                number_format((float) ($compressor['coverage_percentage'] ?? 0), 1, ',', '')
            ),
            'action' => 'De optimalisatiehandleiding noemt meer dan 2–3 starts per uur als mogelijk pendelgedrag. Controleer eerst of starts samenvallen met tapwaterbedrijf of ontdooien. Bespreek daarna eventueel de startgrens voor graadminuten; een lagere grens kan langere runs geven, maar ook meer temperatuurschommeling en ontdooien.',
        ];
    }

    $auxiliaryHeat = $performance['auxiliary_heat_periods']['24h'] ?? [];
    if (is_numeric($auxiliaryHeat['starts'] ?? null)
        && $auxiliaryHeat['starts'] > 0
        && is_numeric($auxiliaryHeat['electricity_kwh'] ?? null)
        && $auxiliaryHeat['electricity_kwh'] > 0) {
        $suggestions[] = [
            'title' => 'Controleer wanneer de elektrische bijverwarming inschakelt',
            'evidence' => sprintf(
                'In de afgelopen 24 uur zijn %d inschakelmomenten waargenomen en is circa %.2f kWh bijverwarming geschat.',
                $auxiliaryHeat['starts'],
                $auxiliaryHeat['electricity_kwh']
            ),
            'action' => 'De verbruiksschatting is gebaseerd op vijf minuten per opgeslagen vermogensmeting. Vergelijk de momenten met buitentemperatuur, graadminuten en ontdooien voordat je een instelling beoordeelt; bijverwarming kan onder koude of ongunstige omstandigheden verwacht zijn.',
        ];
    }

    $averageCop = $performance['periods']['24h']['cop'] ?? null;
    $currentCop = $performance['cop_now'] ?? null;
    if (($performance['cop_warning'] ?? false) === true
        && is_numeric($currentCop)
        && is_numeric($averageCop)) {
        $suggestions[] = [
            'title' => 'Onderzoek de afwijkende actuele COP',
            'evidence' => sprintf(
                'De actuele COP is %.2f; dat wijkt meer dan 20%% af van het gemeten 24-uursgemiddelde van %.2f.',
                $currentCop,
                $averageCop
            ),
            'action' => 'Vergelijk dit moment met buitentemperatuur, tapwaterbedrijf en ontdooien. Een korte afwijking ten opzichte van het eigen daggemiddelde bewijst geen structureel rendementsprobleem en is op zichzelf geen reden om instellingen te wijzigen.',
        ];
    }

    return array_slice($suggestions, 0, 3);
}

function nibe_dashboard_setting_change_input(array $input): array
{
    $setting = trim(is_string($input['setting'] ?? null) ? $input['setting'] : '');
    $newValue = trim(is_string($input['new_value'] ?? null) ? $input['new_value'] : '');
    $oldValue = trim(is_string($input['old_value'] ?? null) ? $input['old_value'] : '');
    $note = trim(is_string($input['note'] ?? null) ? $input['note'] : '');
    $changedAt = $input['changed_at'] ?? null;

    if ($setting === '' || strlen($setting) > 191 || $newValue === '' || strlen($newValue) > 1000
        || strlen($oldValue) > 1000 || strlen($note) > 5000) {
        throw new InvalidArgumentException('Vul een instelling en nieuwe waarde in; de tekst is te lang.');
    }
    if (!is_string($changedAt)
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $changedAt)) {
        throw new InvalidArgumentException('Vul een geldige datum en tijd in.');
    }
    $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $changedAt, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $time->format('Y-m-d\TH:i') !== $changedAt) {
        throw new InvalidArgumentException('Vul een geldige datum en tijd in.');
    }

    return [
        'setting' => $setting,
        'new_value' => $newValue,
        'old_value' => $oldValue === '' ? null : $oldValue,
        'note' => $note === '' ? null : $note,
        'changed_at' => $time->format('Y-m-d H:i:s'),
    ];
}

function nibe_dashboard_compressor_cycles(array $samples, array $units): array
{
    $frequency = $samples['frequency'] ?? [];
    $cycles = [];
    $runStartIndex = null;
    $previous = null;

    foreach ($frequency as $index => $sample) {
        if ($previous !== null && ($sample['time'] <= $previous['time']
            || $sample['time'] - $previous['time'] > 3600)) {
            $runStartIndex = null;
            $previous = null;
        }
        if ($previous !== null && $previous['value'] <= 0 && $sample['value'] > 0) {
            $runStartIndex = $index;
        } elseif ($previous !== null && $previous['value'] > 0 && $sample['value'] <= 0
            && $runStartIndex !== null) {
            $runSamples = array_slice($frequency, $runStartIndex, $index - $runStartIndex);
            $runValues = array_column($runSamples, 'value');
            $start = $frequency[$runStartIndex]['time'];
            $end = $sample['time'];
            $inside = static fn (array $roleSamples): array => array_values(array_filter(
                $roleSamples,
                static fn (array $value): bool => $value['time'] >= $start && $value['time'] <= $end
            ));
            $temperatureAverages = [];
            foreach (['supply', 'return', 'outdoor', 'degree_minutes'] as $role) {
                $values = array_column($inside($samples[$role] ?? []), 'value');
                $temperatureAverages[$role] = $values === [] ? null : array_sum($values) / count($values);
            }
            $hotWater = $inside($samples['hot_water'] ?? []);
            $hotWaterChange = count($hotWater) < 2
                ? null
                : $hotWater[array_key_last($hotWater)]['value'] - $hotWater[0]['value'];
            $degreeMinutes = array_column($inside($samples['degree_minutes'] ?? []), 'value');
            $deltaT = [];
            $returns = $inside($samples['return'] ?? []);
            $usedReturns = [];
            foreach ($inside($samples['supply'] ?? []) as $supply) {
                $nearest = null;
                $distance = 301;
                foreach ($returns as $returnIndex => $return) {
                    if (isset($usedReturns[$returnIndex])) {
                        continue;
                    }
                    $candidate = abs($supply['time'] - $return['time']);
                    if ($candidate < $distance) {
                        $nearest = $returnIndex;
                        $distance = $candidate;
                    }
                }
                if ($nearest !== null && $distance <= 300) {
                    $deltaT[] = $supply['value'] - $returns[$nearest]['value'];
                    $usedReturns[$nearest] = true;
                }
            }
            $normalizedSupply = strtolower(trim((string) ($units['supply'] ?? '')));
            $normalizedReturn = strtolower(trim((string) ($units['return'] ?? '')));
            if (in_array($normalizedSupply, ['c', 'celsius', '°c'], true)) {
                $normalizedSupply = '°c';
            }
            if (in_array($normalizedReturn, ['c', 'celsius', '°c'], true)) {
                $normalizedReturn = '°c';
            }
            $cycleEnergy = static function (string $role) use ($samples, $units, $start, $end): ?float {
                $unit = strtolower(trim((string) ($units[$role] ?? '')));
                $toKw = match ($unit) {
                    'w', 'watts' => static fn (float $value): float => $value / 1000,
                    'kw', 'kilowatts' => static fn (float $value): float => $value,
                    default => null,
                };
                if ($toKw === null) {
                    return null;
                }
                $power = array_values(array_filter(
                    $samples[$role] ?? [],
                    static fn (array $value): bool => $value['time'] >= $start && $value['time'] <= $end
                ));
                if (count($power) < 2) {
                    return null;
                }
                $energy = 0.0;
                $coveredSeconds = 0;
                for ($powerIndex = 0; $powerIndex < count($power) - 1; $powerIndex++) {
                    $seconds = $power[$powerIndex + 1]['time'] - $power[$powerIndex]['time'];
                    if ($seconds <= 0 || $seconds > 900 || $power[$powerIndex]['value'] < 0) {
                        continue;
                    }
                    $coveredSeconds += $seconds;
                    $energy += $toKw($power[$powerIndex]['value']) * $seconds / 3600;
                }

                return $coveredSeconds >= ($end - $start) * 0.7 ? $energy : null;
            };

            $electricity = $cycleEnergy('electric_power');
            $heat = $cycleEnergy('heat_power');
            $averageDeltaT = $normalizedSupply !== '' && $normalizedSupply === $normalizedReturn && $deltaT !== []
                ? array_sum($deltaT) / count($deltaT)
                : null;
            $operation = [];
            if ($averageDeltaT !== null && $averageDeltaT > 0) {
                $operation[] = 'Verwarming';
            } elseif ($averageDeltaT !== null && $averageDeltaT < 0) {
                $operation[] = 'Koeling';
            }
            if ($hotWaterChange !== null && $hotWaterChange >= 1) {
                $operation[] = 'Warm water';
            }

            $cycles[] = [
                'start' => $start,
                'end' => $end,
                'duration_seconds' => $end - $start,
                'degree_minutes_start' => null,
                'degree_minutes_end' => null,
                'degree_minutes_min' => $degreeMinutes === [] ? null : min($degreeMinutes),
                'degree_minutes_max' => $degreeMinutes === [] ? null : max($degreeMinutes),
                'maximum_frequency' => $runValues === [] ? null : max($runValues),
                'average_frequency' => $runValues === [] ? null : array_sum($runValues) / count($runValues),
                'average_supply' => $temperatureAverages['supply'],
                'average_return' => $temperatureAverages['return'],
                'average_delta_t' => $averageDeltaT,
                'average_outdoor' => $temperatureAverages['outdoor'],
                'electricity_kwh' => $electricity,
                'heat_kwh' => $heat,
                'cop' => $electricity !== null && $electricity > 0 && $heat !== null
                    ? $heat / $electricity
                    : null,
                'operation' => $operation === [] ? null : implode(' + ', $operation),
            ];
            $cycleIndex = array_key_last($cycles);
            foreach (['degree_minutes_start' => $start, 'degree_minutes_end' => $end] as $key => $time) {
                $nearest = null;
                $distance = 301;
                foreach ($samples['degree_minutes'] ?? [] as $degreeMinute) {
                    $candidate = abs($degreeMinute['time'] - $time);
                    if ($candidate < $distance) {
                        $nearest = $degreeMinute;
                        $distance = $candidate;
                    }
                }
                $cycles[$cycleIndex][$key] = $nearest === null ? null : $nearest['value'];
            }
            $runStartIndex = null;
        }
        $previous = $sample;
    }

    return $cycles;
}

function nibe_dashboard_cop_by_outdoor_temperature(array $samples, array $units): array
{
    $toKw = static fn (float $value, string $unit): ?float => match (strtolower(trim($unit))) {
        'w', 'watts' => $value / 1000,
        'kw', 'kilowatts' => $value,
        default => null,
    };
    $electricUnit = (string) ($units['electric_power'] ?? '');
    $heatUnit = (string) ($units['heat_power'] ?? '');
    $buckets = [
        '< -5 °C' => ['matches' => [], 'electricity' => 0.0, 'heat' => 0.0, 'covered_seconds' => 0],
        '-5 tot 0 °C' => ['matches' => [], 'electricity' => 0.0, 'heat' => 0.0, 'covered_seconds' => 0],
        '0 tot 5 °C' => ['matches' => [], 'electricity' => 0.0, 'heat' => 0.0, 'covered_seconds' => 0],
        '5 tot 10 °C' => ['matches' => [], 'electricity' => 0.0, 'heat' => 0.0, 'covered_seconds' => 0],
        '10 °C of hoger' => ['matches' => [], 'electricity' => 0.0, 'heat' => 0.0, 'covered_seconds' => 0],
    ];
    if ($toKw(1, $electricUnit) === null || $toKw(1, $heatUnit) === null) {
        return $buckets;
    }

    $matched = [];
    $usedHeat = [];
    $usedOutdoor = [];
    $heatPointer = 0;
    $outdoorPointer = 0;
    foreach ($samples['electric_power'] ?? [] as $electric) {
        $electricKw = $toKw($electric['value'], $electricUnit);
        if ($electricKw === null || $electricKw <= 0) {
            continue;
        }
        while (isset($samples['heat_power'][$heatPointer])
            && ($samples['heat_power'][$heatPointer]['time'] < $electric['time'] - 300
                || isset($usedHeat[$heatPointer]))) {
            $heatPointer++;
        }
        $nearestHeat = null;
        $heatDistance = 301;
        for ($heatIndex = $heatPointer; isset($samples['heat_power'][$heatIndex])
            && $samples['heat_power'][$heatIndex]['time'] <= $electric['time'] + 300; $heatIndex++) {
            $heat = $samples['heat_power'][$heatIndex];
            $distance = abs($electric['time'] - $heat['time']);
            if (!isset($usedHeat[$heatIndex]) && $distance < $heatDistance) {
                $nearestHeat = $heatIndex;
                $heatDistance = $distance;
            }
        }
        if ($nearestHeat === null || $heatDistance > 300) {
            continue;
        }
        while (isset($samples['outdoor'][$outdoorPointer])
            && ($samples['outdoor'][$outdoorPointer]['time'] < $electric['time'] - 300
                || isset($usedOutdoor[$outdoorPointer]))) {
            $outdoorPointer++;
        }
        $nearestOutdoor = null;
        $outdoorDistance = 301;
        for ($outdoorIndex = $outdoorPointer; isset($samples['outdoor'][$outdoorIndex])
            && $samples['outdoor'][$outdoorIndex]['time'] <= $electric['time'] + 300; $outdoorIndex++) {
            $outdoor = $samples['outdoor'][$outdoorIndex];
            $distance = abs($electric['time'] - $outdoor['time']);
            if (!isset($usedOutdoor[$outdoorIndex]) && $distance < $outdoorDistance) {
                $nearestOutdoor = $outdoorIndex;
                $outdoorDistance = $distance;
            }
        }
        if ($nearestOutdoor === null || $outdoorDistance > 300) {
            continue;
        }
        $heatKw = $toKw($samples['heat_power'][$nearestHeat]['value'], $heatUnit);
        if ($heatKw === null || $heatKw < 0) {
            continue;
        }
        $outdoorValue = $samples['outdoor'][$nearestOutdoor]['value'];
        $bucket = match (true) {
            $outdoorValue < -5 => '< -5 °C',
            $outdoorValue < 0 => '-5 tot 0 °C',
            $outdoorValue < 5 => '0 tot 5 °C',
            $outdoorValue < 10 => '5 tot 10 °C',
            default => '10 °C of hoger',
        };
        $matched[] = [
            'time' => max($electric['time'], $samples['heat_power'][$nearestHeat]['time'], $samples['outdoor'][$nearestOutdoor]['time']),
            'outdoor' => $outdoorValue,
            'electric' => $electricKw,
            'heat' => $heatKw,
            'bucket' => $bucket,
        ];
        $usedHeat[$nearestHeat] = true;
        $usedOutdoor[$nearestOutdoor] = true;
    }
    usort($matched, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    foreach ($matched as $index => $sample) {
        $buckets[$sample['bucket']]['matches'][] = $sample;
        $next = $matched[$index + 1] ?? null;
        if ($next !== null && $next['time'] > $sample['time'] && $next['time'] - $sample['time'] <= 900) {
            $seconds = $next['time'] - $sample['time'];
            $buckets[$sample['bucket']]['electricity'] += $sample['electric'] * $seconds / 3600;
            $buckets[$sample['bucket']]['heat'] += $sample['heat'] * $seconds / 3600;
            $buckets[$sample['bucket']]['covered_seconds'] += $seconds;
        }
    }
    foreach ($buckets as &$bucket) {
        $measurements = $bucket['matches'];
        $count = count($measurements);
        $bucket['count'] = $count;
        $bucket['average_outdoor'] = $count === 0
            ? null
            : array_sum(array_column($measurements, 'outdoor')) / $count;
        $bucket['first_time'] = $count === 0 ? null : $measurements[0]['time'];
        $bucket['last_time'] = $count === 0 ? null : $measurements[$count - 1]['time'];
        $bucket['coverage_minutes'] = $bucket['covered_seconds'] / 60;
        $bucket['available'] = $count >= 3 && $bucket['covered_seconds'] >= 600 && $bucket['electricity'] > 0;
        $bucket['cop'] = $bucket['available'] ? $bucket['heat'] / $bucket['electricity'] : null;
        unset($bucket['matches']);
    }
    unset($bucket);

    return $buckets;
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
    $rejected = [];
    foreach (['frequency', 'supply', 'return', 'calculated_supply', 'hot_water', 'outdoor', 'degree_minutes',
        'electric_power', 'heat_power', 'auxiliary_heat_power', 'electric_energy', 'heat_energy', 'defrost'] as $role) {
        $rejected[$role] = [];
        foreach ($observations[$role] ?? [] as $observation) {
            $rawValue = $observation['raw_value'] ?? null;
            $timestamp = $observation['source_timestamp'] ?? $observation['collected_at'] ?? null;
            if (!is_string($rawValue) || !is_numeric($rawValue)) {
                $rejected[$role]['waarde is geen numerieke string'] = true;
                continue;
            }
            if (!is_string($timestamp)) {
                $rejected[$role]['tijd ontbreekt'] = true;
                continue;
            }
            try {
                $time = (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
            } catch (Exception $exception) {
                $rejected[$role]['tijd is ongeldig'] = true;
                continue;
            }
            $value = (float) $rawValue;
            if (!is_finite($value)) {
                $rejected[$role]['waarde is niet eindig'] = true;
                continue;
            }
            if ($time > $nowTimestamp) {
                $rejected[$role]['tijd ligt in de toekomst'] = true;
                continue;
            }
            $samples[$role][] = ['time' => $time, 'value' => $value];
        }
        $samples[$role] ??= [];
        usort($samples[$role], static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
    }

    $check = static fn (bool $ok, string $message): array => ['ok' => $ok, 'message' => $message];
    $roleChecks = static function (string $role, string $label) use ($units, $observations, $samples, $rejected, $check): array {
        $checks = [$check(array_key_exists($role, $units), $label . ' gekoppeld')];
        if (array_key_exists($role, $units)) {
            $checks[] = $check($samples[$role] !== [], $label . ': geldige numerieke meting in opgehaalde historie');
            if ($samples[$role] === []) {
                if (($observations[$role] ?? []) === []) {
                    $checks[] = $check(false, $label . ': geen metingen in opgehaalde historie');
                }
                foreach (array_keys($rejected[$role]) as $reason) {
                    $checks[] = $check(false, $label . ': ' . $reason);
                }
            }
        }
        return $checks;
    };
    $debug = ['periods' => [], 'frequency' => [], 'defrost' => [], 'auxiliary_heat' => ['periods' => []]];
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
            $counterChecks = $roleChecks($role, $metric === 'heat' ? 'Warmteteller' : 'Elektriciteitsteller');
            $counterChecks[] = $check($factor !== null, 'Tellereenheid is kWh of Wh (ontvangen: ' . ($unit !== '' ? $unit : 'leeg') . ')');
            $counterChecks[] = $check($baseline !== null, 'Tellermeting vóór periode gevonden');
            if ($baseline !== null) {
                $age = $start - $baseline['time'];
                $counterChecks[] = $check($age <= 21600, 'Tellermeting vóór periode: ' . $age . ' seconden vóór periodegrens (maximaal 21600)');
            }
            $counterChecks[] = $check($inPeriod !== [], 'Tellermeting in periode gevonden');
            if ($inPeriod !== []) {
                $last = $inPeriod[count($inPeriod) - 1];
                $age = $nowTimestamp - $last['time'];
                $counterChecks[] = $check($age <= 21600, 'Laatste tellermeting: ' . $age . ' seconden oud (maximaal 21600)');
                if ($baseline !== null) {
                    $counterChecks[] = $check($last['value'] >= $baseline['value'], 'Eindstand is niet lager dan beginstand (' . $last['value'] . ' tegenover ' . $baseline['value'] . ')');
                }
            }
            $debug['periods'][$period][$metric] = $counterChecks;
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
        $validPowerUnit = in_array($unit, ['w', 'watts', 'kw', 'kilowatts'], true);
        foreach ($windows as $period => $start) {
            $periodSamples = array_values(array_filter(
                $samples[$role],
                static fn (array $sample): bool => $sample['time'] >= $start
            ));
            $powerChecks = $roleChecks($role, $metric === 'heat' ? 'Warmtevermogen' : 'Elektrisch vermogen');
            $powerChecks[] = $check($validPowerUnit, 'Vermogenseenheid is W of kW (ontvangen: ' . ($unit !== '' ? $unit : 'leeg') . ')');
            $powerChecks[] = $check($periodSamples !== [], 'Vermogensmetingen in periode: ' . count($periodSamples) . ' (minimaal 1)');
            $debug['periods'][$period][$metric] = array_merge($powerChecks, $debug['periods'][$period][$metric]);
            if (!$validPowerUnit || $periodSamples === []) {
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
        $debug['periods'][$period]['cop'] = [
            $check($energy['electricity'] !== null, 'Geïntegreerd elektrisch vermogen beschikbaar'),
            $check($energy['heat'] !== null, 'Geïntegreerd warmtevermogen beschikbaar'),
        ];
        if ($energy['electricity'] !== null) {
            $debug['periods'][$period]['cop'][] = $check($energy['electricity'] > 0, 'Geïntegreerde elektriciteit is positief: ' . $energy['electricity'] . ' kWh');
        }
        foreach (['electricity', 'heat'] as $metric) {
            if ($energy[$metric] === null) {
                // Period COP uses integrated power, not the energy-counter fallback.
                $role = $metric === 'heat' ? 'heat_power' : 'electric_power';
                $unit = strtolower(trim((string) ($units[$role] ?? '')));
                $periodSamples = array_filter($samples[$role], static fn (array $sample): bool => $sample['time'] >= $windows[$period]);
                $debug['periods'][$period]['cop'] = array_merge($debug['periods'][$period]['cop'], $roleChecks($role, $metric === 'heat' ? 'Warmtevermogen' : 'Elektrisch vermogen'), [
                    $check(in_array($unit, ['w', 'watts', 'kw', 'kilowatts'], true), 'Vermogenseenheid is W of kW (ontvangen: ' . ($unit !== '' ? $unit : 'leeg') . ')'),
                    $check($periodSamples !== [], 'Vermogensmetingen in periode: ' . count($periodSamples) . ' (minimaal 1)'),
                ]);
            }
        }
        if ($energy['electricity'] !== null && $energy['electricity'] > 0 && $energy['heat'] !== null) {
            $emptyPeriods[$period]['cop'] = $energy['heat'] / $energy['electricity'];
        }
    }
    $auxiliaryHeatUnit = strtolower(trim((string) ($units['auxiliary_heat_power'] ?? '')));
    $auxiliaryHeatUnitValid = in_array($auxiliaryHeatUnit, ['w', 'watts', 'kw', 'kilowatts'], true);
    $auxiliaryHeatSamples = $samples['auxiliary_heat_power'];
    $auxiliaryHeatPeriods = [];
    foreach ($windows as $period => $start) {
        $periodSamples = array_values(array_filter(
            $auxiliaryHeatSamples,
            static fn (array $sample): bool => $sample['time'] >= $start
        ));
        $starts = 0;
        $hasObservedTransition = false;
        foreach ($auxiliaryHeatSamples as $index => $sample) {
            $previous = $auxiliaryHeatSamples[$index - 1] ?? null;
            if ($sample['time'] < $start || $previous === null
                || $sample['time'] <= $previous['time']
                || $sample['time'] - $previous['time'] > 3600) {
                continue;
            }
            $hasObservedTransition = true;
            if ($previous['value'] <= 0 && $sample['value'] > 0) {
                $starts++;
            }
        }
        $electricityKwh = $periodSamples === [] || !$auxiliaryHeatUnitValid
            ? null
            : array_sum(array_map(
                static fn (array $sample): float => max(
                    0,
                    $powerToKw($sample['value'], $auxiliaryHeatUnit) ?? 0
                ) * 5 / 60,
                $periodSamples
            ));
        $debug['auxiliary_heat']['periods'][$period] = [
            'starts' => array_merge(
                $roleChecks('auxiliary_heat_power', 'Elektrische bijverwarming'),
                [$check($hasObservedTransition, 'Waargenomen vermogensovergang in periode: ' . ($hasObservedTransition ? 'ja' : 'nee'))]
            ),
            'electricity_kwh' => array_merge(
                $roleChecks('auxiliary_heat_power', 'Vermogen elektrische bijverwarming'),
                [
                    $check($auxiliaryHeatUnitValid, 'Vermogenseenheid is W of kW (ontvangen: ' . ($auxiliaryHeatUnit !== '' ? $auxiliaryHeatUnit : 'leeg') . ')'),
                    $check($periodSamples !== [], 'Vermogensmetingen in periode: ' . count($periodSamples) . ' (minimaal 1)'),
                ]
            ),
        ];
        $auxiliaryHeatPeriods[$period] = [
            'starts' => $hasObservedTransition ? $starts : null,
            'electricity_kwh' => $electricityKwh,
        ];
    }
    $latestAuxiliaryHeatPower = $auxiliaryHeatSamples === [] ? null : end($auxiliaryHeatSamples);
    $latestAuxiliaryHeatPowerKw = $latestAuxiliaryHeatPower === null
        ? null
        : $powerToKw($latestAuxiliaryHeatPower['value'], (string) ($units['auxiliary_heat_power'] ?? ''));
    $auxiliaryHeatPowerNow = $latestAuxiliaryHeatPower !== null
        && $nowTimestamp - $latestAuxiliaryHeatPower['time'] <= 900
        && $latestAuxiliaryHeatPowerKw !== null
        && $latestAuxiliaryHeatPowerKw >= 0
        ? $latestAuxiliaryHeatPowerKw
        : null;
    $debug['auxiliary_heat']['power_now'] = array_merge(
        $roleChecks('auxiliary_heat_power', 'Elektrisch bijverwarmingsvermogen'),
        [
            $check($latestAuxiliaryHeatPower !== null, 'Laatste vermogensmeting beschikbaar'),
            $check($auxiliaryHeatUnitValid, 'Vermogenseenheid is W of kW (ontvangen: ' . ($auxiliaryHeatUnit !== '' ? $auxiliaryHeatUnit : 'leeg') . ')'),
        ]
    );
    if ($latestAuxiliaryHeatPower !== null) {
        $age = $nowTimestamp - $latestAuxiliaryHeatPower['time'];
        $debug['auxiliary_heat']['power_now'][] = $check(
            $age >= 0 && $age <= 900,
            'Laatste vermogensmeting: ' . $age . ' seconden oud (tussen 0 en 900)'
        );
        $debug['auxiliary_heat']['power_now'][] = $check(
            $latestAuxiliaryHeatPowerKw !== null && $latestAuxiliaryHeatPowerKw >= 0,
            'Vermogen is niet-negatief en heeft een bruikbare eenheid'
        );
    }
    $periodCoverage = [];
    foreach ($windows as $period => $start) {
        $periodCoverage[$period] = [];
        foreach (['electricity' => 'electric_power', 'heat' => 'heat_power'] as $metric => $powerRole) {
            $role = $integratedPower[$period][$metric] !== null
                ? $powerRole
                : ($metric === 'electricity' ? 'electric_energy' : 'heat_energy');
            $inPeriod = array_values(array_filter(
                $samples[$role],
                static fn (array $sample): bool => $sample['time'] >= $start && $sample['time'] <= $nowTimestamp
            ));
            $isPower = $role === 'electric_power' || $role === 'heat_power';
            $periodCoverage[$period][$metric] = [
                'source' => $role,
                'count' => count($inPeriod),
                'coverage_percentage' => $isPower
                    ? min(100, 100 * count($inPeriod) * 300 / max(1, $nowTimestamp - $start))
                    : null,
                'first_timestamp' => $inPeriod === [] ? null : gmdate('Y-m-d H:i:s', $inPeriod[0]['time']),
                'last_timestamp' => $inPeriod === [] ? null : gmdate('Y-m-d H:i:s', $inPeriod[count($inPeriod) - 1]['time']),
            ];
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
        $differences = [];
        if ($supplyUnit !== '' && $supplyUnit === $returnUnit
            && $supply !== [] && $return !== []) {
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
        $debug['periods'][$period]['average_delta_t'] = array_merge(
            $roleChecks('supply', 'Aanvoer'), $roleChecks('return', 'Retour'),
            [
                $check($supplyUnit !== '' && $supplyUnit === $returnUnit, 'Aanvoer en retour hebben dezelfde niet-lege eenheid (' . $supplyUnit . ', ' . $returnUnit . ')'),
                $check($supply !== [], 'Aanvoermetingen in periode: ' . count($supply)),
                $check($return !== [], 'Retourmetingen in periode: ' . count($return)),
            ]
        );
        if ($supplyUnit !== '' && $supplyUnit === $returnUnit && $supply !== [] && $return !== []) {
            $debug['periods'][$period]['average_delta_t'][] = $check($differences !== [], 'Meetparen met tijdsverschil maximaal 300 seconden: ' . count($differences));
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
    $supply = $samples['supply'] === [] ? null : end($samples['supply']);
    $return = $samples['return'] === [] ? null : end($samples['return']);
    $debug['delta_t_now'] = array_merge($roleChecks('supply', 'Aanvoer'), $roleChecks('return', 'Retour'), [
        $check($supplyUnit !== '' && $supplyUnit === $returnUnit, 'Aanvoer en retour hebben dezelfde niet-lege eenheid (' . $supplyUnit . ', ' . $returnUnit . ')'),
    ]);
    if ($supply !== null && $return !== null) {
        $distance = abs($supply['time'] - $return['time']);
        $age = $nowTimestamp - max($supply['time'], $return['time']);
        $debug['delta_t_now'][] = $check($distance <= 300, 'Tijdsverschil: ' . $distance . ' seconden (maximaal 300)');
        $debug['delta_t_now'][] = $check($age <= 900, 'Recentste meting: ' . $age . ' seconden oud (maximaal 900)');
    }
    if ($supplyUnit !== '' && $supplyUnit === $returnUnit) {
        if ($supply !== null && $return !== null && abs($supply['time'] - $return['time']) <= 300
            && $nowTimestamp - max($supply['time'], $return['time']) <= 900) {
            $deltaTNow = $supply['value'] - $return['value'];
        }
    }

    $cycles = nibe_dashboard_compressor_cycles($samples, $units);
    $startTimes = [];
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
            continue;
        }
        if ($sample['value'] <= 0 && $next['value'] > 0) {
            $startTimes[] = $next['time'];
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
    }
    $runLengths = array_values(array_map(
        static fn (array $cycle): int => $cycle['duration_seconds'],
        array_filter($cycles, static fn (array $cycle): bool => $cycle['start'] >= $windows['24h'])
    ));

    $frequencyWindowValid = [];
    $frequencyCoverage = [];
    foreach (['24h', '7d', '30d'] as $period) {
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
        $coveredSeconds = 0;
        foreach ($samples['frequency'] as $index => $sample) {
            $next = $samples['frequency'][$index + 1] ?? null;
            if ($next !== null && $next['time'] > $sample['time'] && $next['time'] - $sample['time'] <= 3600
                && $next['time'] > $windows[$period]) {
                $coveredSeconds += max(0, min($next['time'], $nowTimestamp) - max($sample['time'], $windows[$period]));
            }
        }
        $frequencyCoverage[$period] = min(100, 100 * $coveredSeconds / ($nowTimestamp - $windows[$period]));
        $latestFrequency = $samples['frequency'] === [] ? null : end($samples['frequency']);
        $frequencyWindowValid[$period] = count($periodSamples) >= 2
            && $frequencyBaseline !== null
            && $windows[$period] - $frequencyBaseline['time'] <= 21600
            && $latestFrequency !== null
            && $nowTimestamp - $latestFrequency['time'] <= 21600
            && $frequencyCoverage[$period] >= 80;
        if ($period !== '30d') {
            $debug['frequency'][$period] = array_merge($roleChecks('frequency', 'Compressorfrequentie'), [
                $check(count($periodSamples) >= 2, 'Frequentiemetingen in periode: ' . count($periodSamples) . ' (minimaal 2)'),
                $check($frequencyBaseline !== null, 'Frequentiemeting vóór periode gevonden'),
                $check($frequencyCoverage[$period] >= 80, 'Datadekking: ' . number_format($frequencyCoverage[$period], 1, ',', '') . '% (minimaal 80%)'),
            ]);
            if ($frequencyBaseline !== null) {
                $age = $windows[$period] - $frequencyBaseline['time'];
                $debug['frequency'][$period][] = $check($age <= 21600, 'Frequentiemeting vóór periode: ' . $age . ' seconden vóór periodegrens (maximaal 21600)');
            }
            if ($latestFrequency !== null) {
                $age = $nowTimestamp - $latestFrequency['time'];
                $debug['frequency'][$period][] = $check($age <= 21600, 'Laatste frequentiemeting: ' . $age . ' seconden oud (maximaal 21600)');
            }
        }
    }
    $debug['runtime'] = array_merge($roleChecks('frequency', 'Compressorfrequentie'), [
        $check($runLengths !== [], 'Voltooide compressorruns met positieve duur waargenomen: ' . count($runLengths) . ' (minimaal 1; gaten van meer dan 3600 seconden onderbreken een run)'),
    ]);
    $debug['frequency_distribution'] = array_merge($debug['frequency']['24h'], [
        $check($observedFrequencySeconds > 0, 'Waargenomen frequentietijd: ' . $observedFrequencySeconds . ' seconden (meer dan 0; geldige intervallen maximaal 3600 seconden)'),
    ]);
    $compressorPeriods = [];
    foreach (['24h', '7d', '30d'] as $period) {
        $start = $windows[$period];
        $periodStarts = array_values(array_filter(
            $startTimes,
            static fn (int $time): bool => $time >= $start && $time <= $nowTimestamp
        ));
        $periodCycles = array_values(array_filter(
            $cycles,
            static fn (array $cycle): bool => $cycle['start'] >= $start && $cycle['start'] <= $nowTimestamp
        ));
        $durations = array_column($periodCycles, 'duration_seconds');
        $runtimeSeconds = 0;
        foreach ($samples['frequency'] as $index => $sample) {
            $next = $samples['frequency'][$index + 1] ?? null;
            if ($sample['value'] > 0 && $next !== null && $next['time'] > $sample['time']
                && $next['time'] - $sample['time'] <= 3600 && $next['time'] > $start) {
                $runtimeSeconds += max(0, min($next['time'], $nowTimestamp) - max($sample['time'], $start));
            }
        }
        $intervals = [];
        for ($index = 1; $index < count($periodStarts); $index++) {
            $intervals[] = $periodStarts[$index] - $periodStarts[$index - 1];
        }
        $complete = $frequencyWindowValid[$period];
        $compressorPeriods[$period] = [
            'complete' => $complete,
            'coverage_percentage' => $frequencyCoverage[$period],
            'starts' => $complete ? count($periodStarts) : null,
            'total_runtime_seconds' => $complete ? $runtimeSeconds : null,
            'average_run_seconds' => $complete && $durations !== [] ? array_sum($durations) / count($durations) : null,
            'longest_run_seconds' => $complete && $durations !== [] ? max($durations) : null,
            'shortest_run_seconds' => $complete && $durations !== [] ? min($durations) : null,
            'runs_under_30_minutes' => $complete ? count(array_filter($durations, static fn (int $duration): bool => $duration < 1800)) : null,
            'runs_under_60_minutes' => $complete ? count(array_filter($durations, static fn (int $duration): bool => $duration < 3600)) : null,
            'average_interval_seconds' => $complete && $intervals !== [] ? array_sum($intervals) / count($intervals) : null,
            'average_delta_t' => $complete ? $emptyPeriods[$period]['average_delta_t'] : null,
        ];
    }
    $compressorComparisons = [];
    foreach (['24h' => 86400, '7d' => 7 * 86400] as $period => $periodSeconds) {
        $previousEnd = $windows[$period];
        $previousStart = $previousEnd - $periodSeconds;
        $previousStarts = array_values(array_filter(
            $startTimes,
            static fn (int $time): bool => $time >= $previousStart && $time < $previousEnd
        ));
        $previousCycles = array_values(array_filter(
            $cycles,
            static fn (array $cycle): bool => $cycle['start'] >= $previousStart && $cycle['start'] < $previousEnd
                && $cycle['end'] < $previousEnd
        ));
        $baseline = null;
        $last = null;
        foreach ($samples['frequency'] as $sample) {
            if ($sample['time'] < $previousStart
                && ($baseline === null || $sample['time'] > $baseline['time'])) {
                $baseline = $sample;
            }
            if ($sample['time'] < $previousEnd
                && ($last === null || $sample['time'] > $last['time'])) {
                $last = $sample;
            }
        }
        $previousCoverageSeconds = 0;
        $previousRuntimeSeconds = 0;
        foreach ($samples['frequency'] as $index => $sample) {
            $next = $samples['frequency'][$index + 1] ?? null;
            if ($next === null || $next['time'] <= $sample['time'] || $next['time'] - $sample['time'] > 3600
                || $next['time'] <= $previousStart || $sample['time'] >= $previousEnd) {
                continue;
            }
            $seconds = max(0, min($next['time'], $previousEnd) - max($sample['time'], $previousStart));
            $previousCoverageSeconds += $seconds;
            if ($sample['value'] > 0) {
                $previousRuntimeSeconds += $seconds;
            }
        }
        $coveragePercentage = min(100, 100 * $previousCoverageSeconds / $periodSeconds);
        $previousComplete = count(array_filter(
            $samples['frequency'],
            static fn (array $sample): bool => $sample['time'] >= $previousStart && $sample['time'] < $previousEnd
        )) >= 2 && $baseline !== null && $previousStart - $baseline['time'] <= 21600
            && $last !== null && $previousEnd - $last['time'] <= 21600
            && $coveragePercentage >= 80;
        $previousDurations = array_column($previousCycles, 'duration_seconds');
        $previous = [
            'complete' => $previousComplete,
            'coverage_percentage' => $coveragePercentage,
            'starts' => $previousComplete ? count($previousStarts) : null,
            'total_runtime_seconds' => $previousComplete ? $previousRuntimeSeconds : null,
            'average_run_seconds' => $previousComplete && $previousDurations !== []
                ? array_sum($previousDurations) / count($previousDurations)
                : null,
            'runs_under_30_minutes' => $previousComplete
                ? count(array_filter($previousDurations, static fn (int $duration): bool => $duration < 1800))
                : null,
        ];
        $current = $compressorPeriods[$period];
        $changes = [];
        foreach (['starts', 'total_runtime_seconds', 'average_run_seconds', 'runs_under_30_minutes'] as $metric) {
            $changes[$metric] = $current[$metric] !== null && $previous[$metric] !== null
                ? $current[$metric] - $previous[$metric]
                : null;
        }
        $compressorComparisons[$period] = ['previous' => $previous, 'changes' => $changes];
    }
    $defrostPeriods = [];
    foreach (['24h', '7d', '30d'] as $period) {
        $start = $windows[$period];
        $defrostStarts = 0;
        $defrostStartTimes = [];
        $defrostSeconds = 0;
        $defrostDurations = [];
        $defrostCycles = [];
        $defrostOutdoorValues = [];
        $activeDefrostStart = null;
        $activeDefrostSeconds = 0;
        $lastDefrostStartTime = null;
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
            if ($active && $previousDefrost === false) {
                $lastDefrostStartTime = $sample['time'];
            }
            if ($sample['time'] >= $start && $active && $previousDefrost === false) {
                $defrostStarts++;
                $defrostStartTimes[] = $sample['time'];
            }
            if ($sample['time'] >= $start && $active) {
                $nearestOutdoor = null;
                $outdoorDistance = 301;
                foreach ($samples['outdoor'] as $outdoor) {
                    $distance = abs($sample['time'] - $outdoor['time']);
                    if ($distance < $outdoorDistance) {
                        $nearestOutdoor = $outdoor;
                        $outdoorDistance = $distance;
                    }
                }
                if ($nearestOutdoor !== null && $outdoorDistance <= 300) {
                    $defrostOutdoorValues[] = $nearestOutdoor['value'];
                }
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
                        $defrostCycles[] = [$activeDefrostStart, $next['time']];
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
        $defrostCoverageSeconds = 0;
        foreach ($samples['defrost'] as $index => $sample) {
            $next = $samples['defrost'][$index + 1] ?? null;
            if ($next !== null && $next['time'] > $sample['time'] && $next['time'] - $sample['time'] <= 3600
                && $next['time'] > $start) {
                $defrostCoverageSeconds += max(0, min($next['time'], $nowTimestamp) - max($sample['time'], $start));
            }
        }
        $defrostCoveragePercentage = min(100, 100 * $defrostCoverageSeconds / ($nowTimestamp - $start));
        $hasPeriodData = count(array_filter(
            $samples['defrost'],
            static fn (array $sample): bool => $sample['time'] >= $start
        )) >= 2 && $defrostBaseline !== null && $start - $defrostBaseline['time'] <= 21600
            && $latestDefrost !== null && $nowTimestamp - $latestDefrost['time'] <= 21600;
        $periodCount = count(array_filter($samples['defrost'], static fn (array $sample): bool => $sample['time'] >= $start));
        $defrostChecks = array_merge($roleChecks('defrost', 'Ontdooistatus'), [
            $check($periodCount >= 2, 'Ontdooimetingen in periode: ' . $periodCount . ' (minimaal 2)'),
            $check($defrostBaseline !== null, 'Ontdooimeting vóór periode gevonden'),
            $check(true, 'Datadekking: ' . number_format($defrostCoveragePercentage, 1, ',', '') . '% (informatief; intervallen van maximaal 1 uur tellen mee)'),
        ]);
        if ($defrostBaseline !== null) {
            $age = $start - $defrostBaseline['time'];
            $defrostChecks[] = $check($age <= 21600, 'Ontdooimeting vóór periode: ' . $age . ' seconden vóór periodegrens (maximaal 21600)');
        }
        if ($latestDefrost !== null) {
            $age = $nowTimestamp - $latestDefrost['time'];
            $defrostChecks[] = $check($age <= 21600, 'Laatste ontdooimeting: ' . $age . ' seconden oud (maximaal 21600)');
        }
        $debug['defrost'][$period]['count'] = $defrostChecks;
        $debug['defrost'][$period]['seconds'] = $defrostChecks;
        $debug['defrost'][$period]['average_duration_seconds'] = array_merge($defrostChecks, [
            $check($defrostDurations !== [], 'Voltooide ontdooicycli: ' . count($defrostDurations) . ' (minimaal 1)'),
        ]);
        $debug['defrost'][$period]['average_interval_seconds'] = array_merge($defrostChecks, [
            $check(count($defrostStartTimes) >= 2, 'Waargenomen ontdooistarts: ' . count($defrostStartTimes) . ' (minimaal 2)'),
        ]);
        $debug['defrost'][$period]['heat_sources'] = array_merge($defrostChecks, [
            $check($defrostDurations !== [], 'Voltooide ontdooicycli: ' . count($defrostDurations) . ' (minimaal 1)'),
        ]);
        $debug['defrost'][$period]['longest_duration_seconds'] = array_merge($defrostChecks, [
            $check($defrostDurations !== [], 'Voltooide ontdooicycli: ' . count($defrostDurations) . ' (minimaal 1)'),
        ]);
        $debug['defrost'][$period]['since_last_seconds'] = array_merge($defrostChecks, [
            $check($lastDefrostStartTime !== null, 'Een laatste ontdooistart is waargenomen'),
        ]);
        $debug['defrost'][$period]['average_outdoor'] = array_merge($defrostChecks, $roleChecks('outdoor', 'Buitentemperatuur'), [
            $check($defrostOutdoorValues !== [], 'Buitentemperatuurmetingen binnen 5 minuten van ontdooimetingen: ' . count($defrostOutdoorValues)),
        ]);
        $debug['defrost'][$period]['percent'] = array_merge($defrostChecks, $roleChecks('frequency', 'Compressorfrequentie'), [
            $check($periodRunningSeconds > 0, 'Waargenomen compressordraaitijd: ' . $periodRunningSeconds . ' seconden (meer dan 0)'),
            $check($defrostSeconds <= $periodRunningSeconds, 'Ontdooitijd (' . $defrostSeconds . ' s) is niet groter dan draaitijd (' . $periodRunningSeconds . ' s)'),
        ]);
        $heatSources = ['heating' => 0, 'hot_water' => 0, 'auxiliary' => 0, 'unknown' => 0];
        $valueAt = static function (array $series, int $time): ?float {
            $found = null;
            foreach ($series as $sample) {
                if ($sample['time'] <= $time + 300 && $sample['time'] >= $time - 900
                    && ($found === null || abs($sample['time'] - $time) < abs($found['time'] - $time))) {
                    $found = $sample;
                }
            }
            return $found === null ? null : $found['value'];
        };
        foreach ($defrostCycles as [$cycleStart, $cycleEnd]) {
            $auxiliaryActive = false;
            foreach ($samples['auxiliary_heat_power'] as $sample) {
                if ($sample['time'] >= $cycleStart && $sample['time'] <= $cycleEnd && $sample['value'] > 0) {
                    $auxiliaryActive = true;
                    break;
                }
            }
            if ($auxiliaryActive) {
                $heatSources['auxiliary']++;
                continue;
            }
            $drops = [];
            foreach (['heating' => 'return', 'hot_water' => 'hot_water'] as $source => $role) {
                $before = $valueAt($samples[$role], $cycleStart);
                $after = $valueAt($samples[$role], $cycleEnd);
                if ($before !== null && $after !== null && $before - $after >= 0.5) {
                    $drops[$source] = $before - $after;
                }
            }
            if ($drops === []) {
                $heatSources['unknown']++;
            } else {
                arsort($drops);
                $heatSources[array_key_first($drops)]++;
            }
        }
        $defrostPeriods[$period] = [
            'heat_sources' => $hasPeriodData && $defrostCycles !== [] ? $heatSources : null,
            'count' => $hasPeriodData ? $defrostStarts : null,
            'start_times' => $hasPeriodData ? $defrostStartTimes : [],
            'seconds' => $hasPeriodData ? $defrostSeconds : null,
            'average_duration_seconds' => $hasPeriodData && $defrostDurations !== []
                ? array_sum($defrostDurations) / count($defrostDurations)
                : null,
            'longest_duration_seconds' => $hasPeriodData && $defrostDurations !== [] ? max($defrostDurations) : null,
            'average_interval_seconds' => $hasPeriodData && count($defrostStartTimes) >= 2
                ? (max($defrostStartTimes) - min($defrostStartTimes)) / (count($defrostStartTimes) - 1)
                : null,
            'since_last_seconds' => $hasPeriodData && $lastDefrostStartTime !== null
                ? $nowTimestamp - $lastDefrostStartTime
                : null,
            'average_outdoor' => $hasPeriodData && $defrostOutdoorValues !== []
                ? array_sum($defrostOutdoorValues) / count($defrostOutdoorValues)
                : null,
            'coverage_percentage' => $defrostCoveragePercentage,
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
    $debug['cop_now'] = array_merge($roleChecks('electric_power', 'Elektrisch vermogen'), $roleChecks('heat_power', 'Warmtevermogen'));
    foreach (['electric_power' => $latestElectricPower, 'heat_power' => $latestHeatPower] as $role => $sample) {
        $label = $role === 'heat_power' ? 'Warmtevermogen' : 'Elektrisch vermogen';
        $unit = strtolower(trim((string) ($units[$role] ?? '')));
        if ($sample !== null) {
            $age = $nowTimestamp - $sample['time'];
            $kw = $powerToKw($sample['value'], $unit);
            $debug['cop_now'][] = $check($age <= 900, $label . ': meting ' . $age . ' seconden oud (maximaal 900)');
            $debug['cop_now'][] = $check($kw !== null, $label . ': eenheid is W of kW (ontvangen: ' . ($unit !== '' ? $unit : 'leeg') . ')');
            if ($kw !== null) {
                $debug['cop_now'][] = $check($role === 'heat_power' ? $kw >= 0 : $kw > 0,
                    $label . ': ' . $kw . ' kW (' . ($role === 'heat_power' ? 'minimaal 0' : 'meer dan 0') . ')');
            }
        }
    }
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
    $copByOutdoor = nibe_dashboard_cop_by_outdoor_temperature($samples, $units);

    return [
        'periods' => $emptyPeriods,
        'data_coverage' => $periodCoverage,
        'cop_now' => $latestCop,
        'cop_now_timestamp' => $latestCop !== null ? gmdate('Y-m-d H:i:s', $latestCopTime) : null,
        'cop_warning' => $copWarning,
        'delta_t_now' => $deltaTNow,
        'compressor_starts' => $compressorPeriods['24h']['starts'],
        'compressor_starts_7d' => $compressorPeriods['7d']['starts'],
        'average_run_seconds' => $runLengths === [] ? null : array_sum($runLengths) / count($runLengths),
        'longest_run_seconds' => $runLengths === [] ? null : max($runLengths),
        'shortest_run_seconds' => $runLengths === [] ? null : min($runLengths),
        'compressor_periods' => $compressorPeriods,
        'compressor_comparisons' => $compressorComparisons,
        'cycles' => array_values(array_filter(
            $cycles,
            static fn (array $cycle): bool => $cycle['start'] >= $windows['30d']
        )),
        'frequency_distribution' => $frequencyDistribution,
        'frequency_seconds' => $frequencySeconds,
        'cop_by_outdoor_temperature' => $copByOutdoor,
        'defrost_periods' => $defrostPeriods,
        'auxiliary_heat_power_now' => $auxiliaryHeatPowerNow,
        'auxiliary_heat_power_timestamp' => $auxiliaryHeatPowerNow !== null
            ? gmdate('Y-m-d H:i:s', $latestAuxiliaryHeatPower['time'])
            : null,
        'auxiliary_heat_periods' => $auxiliaryHeatPeriods,
        'debug' => $debug,
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
