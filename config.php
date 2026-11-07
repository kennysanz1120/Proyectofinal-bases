<?php
declare(strict_types=1);
$url = getenv('DATABASE_URL') ?: '';
if ($url !== '') {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['path'])) throw new RuntimeException('DATABASE_URL no tiene un formato PostgreSQL válido.');
    parse_str($p['query'] ?? '', $query);
    $dsn = 'pgsql:host=' . $p['host'] . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ltrim($p['path'], '/') . ';sslmode=' . ($query['sslmode'] ?? 'require');
} else {
    $dsn = 'pgsql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'eps_villavicencio');
}
return ['dsn'=>$dsn,'connections'=>[
    'app'=>['user'=>getenv('DB_USER') ?: 'eps_app','password'=>getenv('DB_PASSWORD') ?: ''],
    'admin'=>['user'=>getenv('DB_ADMIN_USER') ?: 'eps_admin','password'=>getenv('DB_ADMIN_PASSWORD') ?: ''],
]];
