<?php

declare(strict_types=1);

function db(array $config): PDO
{
    // Supports the nested format written by install.php / config.example.php
    // ($config['db']['host'], ...) and the older flat keys ($config['db_host'], ...).
    $nested = is_array($config['db'] ?? null) ? $config['db'] : [];

    $host = $nested['host'] ?? $config['db_host'] ?? 'localhost';
    $user = $nested['user'] ?? $config['db_user'] ?? 'root';
    $password = $nested['pass'] ?? $config['db_password'] ?? '';
    $name = $nested['name'] ?? $config['db_name'] ?? 'certificates';
    $port = (int)($nested['port'] ?? $config['db_port'] ?? 3306);

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $db = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ]);

    return $db;
}
