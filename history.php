<?php

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/dashboard.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$request = nibe_dashboard_history_request($_GET);
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $request === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige aanvraag.']);
    exit;
}

try {
    $database = nibe_database();
    $pointQuery = $database->prepare(
        'SELECT p.id, p.name, p.unit, p.source_point_id, d.source_device_id, d.display_name AS device_name
         FROM nibe_measurement_points p
         INNER JOIN nibe_devices d ON d.id = p.device_id
         WHERE p.id = ? AND p.enabled = 1'
    );
    $sourceHistoryQuery = $database->prepare(
        'SELECT id, raw_value, source_timestamp, collected_at
         FROM nibe_observations
         WHERE measurement_point_id = ?
           AND source_timestamp >= ? AND source_timestamp <= ?
           AND LENGTH(raw_value) <= 128
         ORDER BY source_timestamp DESC, id DESC
         LIMIT 3001'
    );
    $collectedHistoryQuery = $database->prepare(
        'SELECT id, raw_value, source_timestamp, collected_at
         FROM nibe_observations
         WHERE measurement_point_id = ?
           AND collected_at >= ? AND collected_at <= ?
           AND source_timestamp IS NULL AND LENGTH(raw_value) <= 128
         ORDER BY collected_at DESC, id DESC
         LIMIT 3001'
    );
    $series = [];
    foreach ($request['points'] as $id) {
        $pointQuery->execute([$id]);
        $point = $pointQuery->fetch();
        if ($point === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Onbekend meetpunt.']);
            exit;
        }
        $parameters = [
            $id,
            gmdate('Y-m-d H:i:s', $request['start']),
            gmdate('Y-m-d H:i:s', $request['end']),
        ];
        $sourceHistoryQuery->execute($parameters);
        $collectedHistoryQuery->execute($parameters);
        $observations = array_merge($sourceHistoryQuery->fetchAll(), $collectedHistoryQuery->fetchAll());
        usort($observations, static function (array $left, array $right): int {
            $timeOrder = strcmp(
                $right['source_timestamp'] ?? $right['collected_at'],
                $left['source_timestamp'] ?? $left['collected_at']
            );
            return $timeOrder !== 0 ? $timeOrder : ($right['id'] <=> $left['id']);
        });
        $truncated = count($observations) > 3000;
        if ($truncated) {
            $observations = array_slice($observations, 0, 3000);
        }
        foreach ($observations as &$observation) {
            unset($observation['id']);
        }
        unset($observation);
        $point['display_name'] = nibe_dashboard_point_name($point);
        $series[] = ['point' => $point, 'observations' => array_reverse($observations), 'truncated' => $truncated];
    }
    echo json_encode(['series' => $series], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Historie is niet beschikbaar.']);
}
