<?php

require_once __DIR__ . '/app/collector.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nibe_collection_response(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    nibe_collection_response(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$expectedToken = nibe_env('NIBE_COLLECTOR_TOKEN');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$basicUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$basicPassword = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!nibe_collector_authorized($expectedToken, $authorization, $basicUser, $basicPassword)) {
    if ($expectedToken !== null
        && $expectedToken !== ''
        && $authorization === ''
        && $basicUser === ''
        && $basicPassword === '') {
        header('WWW-Authenticate: Basic realm="NIBE collector", charset="UTF-8"');
        nibe_collection_response(401, ['success' => false, 'error' => 'Collector authorization required.']);
    }

    nibe_collection_response(403, ['success' => false, 'error' => 'Collector authorization failed.']);
}

try {
    $database = nibe_database();
    $clientId = nibe_env('NIBE_MYUPLINK_CLIENT_ID');
    $clientSecret = nibe_env('NIBE_MYUPLINK_CLIENT_SECRET');
    if ($clientId === null || $clientSecret === null) {
        throw new RuntimeException('myUplink OAuth client is not configured.');
    }

    $result = nibe_collect($database, new NibeMyUplinkClient($clientId, $clientSecret));
    nibe_collection_response(200, ['success' => true] + $result);
} catch (Throwable $exception) {
    $busy = $exception->getMessage() === 'A collection is already running.';
    nibe_collection_response($busy ? 409 : 502, [
        'success' => false,
        'error' => $busy ? $exception->getMessage() : 'Collection failed. Check server-side diagnostics.',
    ]);
}
