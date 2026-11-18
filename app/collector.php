<?php

require_once __DIR__ . '/config.php';

final class NibeMyUplinkClient
{
    private const API_BASE_URL = 'https://api.myuplink.com/v2';
    private const TOKEN_URL = 'https://api.myuplink.com/oauth/token';
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret
    )
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('myUplink OAuth client is not configured.');
        }
    }

    public function getSystems(): array
    {
        $response = $this->get('/systems/me');
        $systems = $response['systems'] ?? $response;

        if (!is_array($systems)) {
            throw new RuntimeException('myUplink returned an invalid systems response.');
        }

        return array_is_list($systems) ? $systems : [$systems];
    }

    public function getPoints(string $deviceId): array
    {
        $response = $this->get('/devices/' . rawurlencode($deviceId) . '/points');

        if (!is_array($response)) {
            throw new RuntimeException('myUplink returned an invalid points response.');
        }

        return array_is_list($response) ? $response : ($response['points'] ?? []);
    }

    private function get(string $path): array
    {
        $attempt = 0;

        do {
            $headers = [];
            $handle = curl_init(self::API_BASE_URL . $path);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken(),
                ],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($header);
                },
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_errno($handle);
            curl_close($handle);

            if ($body === false || $curlError !== 0) {
                throw new RuntimeException('Could not reach myUplink.');
            }

            if ($status === 429) {
                $retryAfter = $this->retryAfterSeconds($headers['retry-after'] ?? null);
                if ($attempt === 0 && $retryAfter !== null && $retryAfter <= 5) {
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }
                    $attempt++;
                    continue;
                }

                throw new RuntimeException('myUplink rate limit reached; collection should be retried later.');
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('myUplink returned HTTP ' . $status . '.');
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException) {
                throw new RuntimeException('myUplink returned invalid JSON.');
            }

            if (!is_array($decoded)) {
                throw new RuntimeException('myUplink returned an invalid response.');
            }

            return $decoded;
        } while ($attempt < 2);

        throw new RuntimeException('myUplink request failed.');
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = [];
            $handle = curl_init(self::TOKEN_URL);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'READSYSTEM',
                ]),
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($header);
                },
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_errno($handle);
            curl_close($handle);

            if ($body === false || $curlError !== 0) {
                throw new RuntimeException('Could not authenticate with myUplink.');
            }
            if ($status === 429) {
                $retryAfter = $this->retryAfterSeconds($headers['retry-after'] ?? null);
                if ($attempt === 0 && $retryAfter !== null && $retryAfter <= 5) {
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }
                    continue;
                }

                throw new RuntimeException('myUplink rate limit reached; collection should be retried later.');
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('myUplink authentication returned HTTP ' . $status . '.');
            }

            try {
                $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('myUplink returned invalid authentication data.');
            }

            if (!is_array($response)
                || !isset($response['access_token'])
                || !is_string($response['access_token'])
                || $response['access_token'] === '') {
                throw new RuntimeException('myUplink returned invalid authentication data.');
            }

            $this->accessToken = $response['access_token'];

            return $this->accessToken;
        }

        throw new RuntimeException('myUplink authentication failed.');
    }

    private function retryAfterSeconds(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $retryAt = strtotime($value);

        return $retryAt === false ? null : max(0, $retryAt - time());
    }
}

function nibe_raw_value(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value)) {
        return (string) $value;
    }

    if (is_float($value) || is_bool($value) || is_array($value)) {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    throw new RuntimeException('myUplink returned an unsupported measurement value.');
}

function nibe_source_timestamp(mixed $timestamp): ?string
{
    if ($timestamp === null || $timestamp === '') {
        return null;
    }

    if (!is_string($timestamp)) {
        throw new RuntimeException('myUplink returned an invalid measurement timestamp.');
    }

    try {
        $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new RuntimeException('myUplink returned an invalid measurement timestamp.');
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function nibe_collection_error(Throwable $exception): string
{
    return get_class($exception) === RuntimeException::class
        ? substr($exception->getMessage(), 0, 500)
        : 'Collection failed due to a database or internal error.';
}

function nibe_collect(PDO $database, NibeMyUplinkClient $client): array
{
    $databaseName = $database->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($databaseName) || $databaseName === '') {
        throw new RuntimeException('The collector database is not selected.');
    }

    $lockName = 'nibe_collection_' . substr(hash('sha256', $databaseName), 0, 40);
    $lockQuery = $database->prepare('SELECT GET_LOCK(?, 0)');
    $lockQuery->execute([$lockName]);
    $lock = $lockQuery->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('A collection is already running.');
    }

    $runId = null;
    $connectionId = null;
    $counts = ['devices' => 0, 'points' => 0, 'observations' => 0];

    try {
        $database->exec("INSERT INTO nibe_collection_runs (started_at, result) VALUES (UTC_TIMESTAMP(6), 'running')");
        $runId = (int) $database->lastInsertId();

        $connectionQuery = $database->prepare('SELECT id FROM nibe_connections WHERE source = ? LIMIT 1');
        $connectionQuery->execute(['myuplink']);
        $connectionId = $connectionQuery->fetchColumn();

        if ($connectionId === false) {
            $database->exec("INSERT INTO nibe_connections (source, authorization_status) VALUES ('myuplink', 'authorized')");
            $connectionId = (int) $database->lastInsertId();
        } else {
            $connectionId = (int) $connectionId;
            $updateConnection = $database->prepare(
                "UPDATE nibe_connections SET authorization_status = 'authorized', last_error = NULL WHERE id = ?"
            );
            $updateConnection->execute([$connectionId]);
        }

        $database->beginTransaction();
        $deviceUpsert = $database->prepare(
            'INSERT INTO nibe_devices (connection_id, source_device_id, display_name) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), id = LAST_INSERT_ID(id)'
        );
        $pointUpsert = $database->prepare(
            'INSERT INTO nibe_measurement_points (device_id, source_point_id, name, unit, value_type)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), unit = VALUES(unit), value_type = VALUES(value_type),
             id = LAST_INSERT_ID(id)'
        );
        $observationInsert = $database->prepare(
            'INSERT INTO nibe_observations (measurement_point_id, source_timestamp, collected_at, raw_value)
             VALUES (?, ?, UTC_TIMESTAMP(6), ?)
             ON DUPLICATE KEY UPDATE raw_value = VALUES(raw_value)'
        );

        foreach ($client->getSystems() as $system) {
            if (!is_array($system) || !isset($system['devices']) || !is_array($system['devices'])) {
                throw new RuntimeException('myUplink returned a system without devices.');
            }

            foreach ($system['devices'] as $device) {
                if (!is_array($device)) {
                    throw new RuntimeException('myUplink returned an invalid device.');
                }
                $sourceDeviceId = $device['deviceId'] ?? $device['id'] ?? null;
                if (!is_string($sourceDeviceId) && !is_int($sourceDeviceId)) {
                    throw new RuntimeException('myUplink returned a device without an identifier.');
                }
                $sourceDeviceId = (string) $sourceDeviceId;
                $deviceName = $device['name'] ?? $sourceDeviceId;
                if (!is_string($deviceName)) {
                    throw new RuntimeException('myUplink returned an invalid device name.');
                }

                $deviceUpsert->execute([$connectionId, $sourceDeviceId, $deviceName]);
                $deviceId = (int) $database->lastInsertId();
                $counts['devices']++;

                foreach ($client->getPoints($sourceDeviceId) as $point) {
                    if (!is_array($point)) {
                        throw new RuntimeException('myUplink returned an invalid measurement point.');
                    }
                    $sourcePointId = $point['parameterId'] ?? $point['pointId'] ?? null;
                    if (!is_string($sourcePointId) && !is_int($sourcePointId)) {
                        throw new RuntimeException('myUplink returned a measurement point without an identifier.');
                    }
                    $sourcePointId = (string) $sourcePointId;
                    $pointName = $point['name'] ?? $point['parameterName'] ?? $sourcePointId;
                    if (!is_string($pointName)) {
                        throw new RuntimeException('myUplink returned an invalid measurement point name.');
                    }
                    $unit = $point['unit'] ?? $point['parameterUnit'] ?? null;
                    if ($unit !== null && !is_string($unit)) {
                        throw new RuntimeException('myUplink returned an invalid measurement unit.');
                    }
                    $valueType = $point['valueType'] ?? $point['type'] ?? 'unknown';
                    if (!is_string($valueType)) {
                        throw new RuntimeException('myUplink returned an invalid measurement type.');
                    }

                    $pointUpsert->execute([$deviceId, $sourcePointId, $pointName, $unit, $valueType]);
                    $pointId = (int) $database->lastInsertId();
                    $counts['points']++;

                    if (!array_key_exists('value', $point) || $point['value'] === null) {
                        continue;
                    }

                    $sourceTimestamp = nibe_source_timestamp($point['timestamp'] ?? null);
                    $observationInsert->execute([$pointId, $sourceTimestamp, nibe_raw_value($point['value'])]);
                    $counts['observations']++;
                }
            }
        }

        $database->commit();
        $success = $database->prepare(
            'UPDATE nibe_connections SET last_success_at = UTC_TIMESTAMP(6), last_error = NULL WHERE id = ?'
        );
        $success->execute([$connectionId]);
        $finish = $database->prepare(
            "UPDATE nibe_collection_runs SET ended_at = UTC_TIMESTAMP(6), result = 'success' WHERE id = ?"
        );
        $finish->execute([$runId]);

        return ['run_id' => $runId] + $counts;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        if ($connectionId !== null) {
            $failure = $database->prepare(
                "UPDATE nibe_connections SET authorization_status = 'error', last_error = ? WHERE id = ?"
            );
            $failure->execute([nibe_collection_error($exception), $connectionId]);
        }
        if ($runId !== null) {
            $finish = $database->prepare(
                "UPDATE nibe_collection_runs SET ended_at = UTC_TIMESTAMP(6), result = 'failure', error_message = ? WHERE id = ?"
            );
            $finish->execute([nibe_collection_error($exception), $runId]);
        }

        throw $exception;
    } finally {
        $release = $database->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
