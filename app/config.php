<?php

function nibe_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

function nibe_database_config(): array
{
    $config = [
        'host' => nibe_env('NIBE_DB_HOST'),
        'name' => nibe_env('NIBE_DB_NAME'),
        'user' => nibe_env('NIBE_DB_USER'),
        'password' => nibe_env('NIBE_DB_PASSWORD'),
    ];

    foreach (['host', 'name', 'user', 'password'] as $key) {
        if ($config[$key] === null || $config[$key] === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }

    return $config;
}

function nibe_database(): PDO
{
    $config = nibe_database_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['name']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
