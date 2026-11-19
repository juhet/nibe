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
        'SELECT p.id, p.name, p.unit, p.source_point_id, d.display_name AS device_name
         FROM nibe_measurement_points p
         INNER JOIN nibe_devices d ON d.id = p.device_id
         WHERE p.id = ? AND p.enabled = 1'
    );
    $historyQuery = $database->prepare(
        'SELECT raw_value, source_timestamp, collected_at
         FROM nibe_observations
         WHERE measurement_point_id = ?
           AND COALESCE(source_timestamp, collected_at) >= ?
           AND COALESCE(source_timestamp, collected_at) <= ?
           AND LENGTH(raw_value) <= 128
         ORDER BY COALESCE(source_timestamp, collected_at) DESC, id DESC
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
        $historyQuery->execute([
            $id,
            gmdate('Y-m-d H:i:s', $request['start']),
            gmdate('Y-m-d H:i:s', $request['end']),
        ]);
        $observations = $historyQuery->fetchAll();
        $truncated = count($observations) > 3000;
        if ($truncated) {
            array_pop($observations);
        }
        $series[] = ['point' => $point, 'observations' => array_reverse($observations), 'truncated' => $truncated];
    }
    echo json_encode(['series' => $series], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Historie is niet beschikbaar.']);
}
