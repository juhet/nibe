<?php

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

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Could not reserve a local test port: ' . $error);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);

$environment = getenv();
$environment['NIBE_COLLECTOR_TOKEN'] = 'collector-test-token';
$environment['NIBE_DB_HOST'] = '';
$server = proc_open(
    [PHP_BINARY, '-S', $address, '-t', dirname(__DIR__)],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    dirname(__DIR__),
    $environment
);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start the test server.');
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Test server did not start.');
    }

    $request = static function (string $method, ?string $authorization = null) use ($address): array {
        $headers = $authorization === null ? '' : "Authorization: $authorization\r\n";
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
        ]]);
        $body = file_get_contents('http://' . $address . '/collect.php', false, $context);
        if ($body === false) {
            throw new RuntimeException('Test request failed.');
        }

        return [$http_response_header, $body];
    };

    [$headers, $body] = $request('HEAD');
    expect_same(true, str_contains($headers[0], ' 401 '), 'HEAD requires authentication');
    expect_same('', $body, 'unauthenticated HEAD has no body');

    [$headers, $body] = $request('HEAD', 'Bearer ' . 'wrong-token');
    expect_same(true, str_contains($headers[0], ' 403 '), 'HEAD rejects incorrect token');
    expect_same('', $body, 'rejected HEAD has no body');

    [$headers, $body] = $request('HEAD', 'Bearer ' . 'collector-test-token');
    expect_same(true, str_contains($headers[0], ' 502 '), 'authenticated HEAD attempts collection');
    expect_same('', $body, 'authenticated HEAD has no body');

    [$headers, $body] = $request('GET', 'Bearer ' . 'collector-test-token');
    expect_same(true, str_contains($headers[0], ' 502 '), 'GET still attempts collection');
    expect_same(false, json_decode($body, true)['success'], 'GET retains JSON error response');

    [$headers] = $request('POST', 'Bearer ' . 'collector-test-token');
    expect_same(true, str_contains($headers[0], ' 405 '), 'other methods are rejected');
    expect_same(true, in_array('Allow: GET, HEAD', $headers, true), 'allowed methods are advertised');
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo "Collector HTTP tests passed.\n";
