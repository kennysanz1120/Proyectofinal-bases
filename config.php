<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'eps_villavicencio';

return [
    'dsn' => "pgsql:host={$host};port={$port};dbname={$name}",
    'user' => getenv('DB_USER') ?: 'eps_app',
    'password' => getenv('DB_PASSWORD') ?: '2345',
];
