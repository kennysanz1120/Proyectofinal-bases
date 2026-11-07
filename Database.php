<?php
declare(strict_types=1);

final class Database
{
    private static array $instances = [];

    public static function getInstance(string $scope = 'app'): PDO
    {
        if (isset(self::$instances[$scope])) return self::$instances[$scope];
        $cfg = require __DIR__ . '/config.php';
        $credentials = $cfg['connections'][$scope] ?? null;
        if (!$credentials) throw new RuntimeException('No está configurada la conexión solicitada.');
        return self::$instances[$scope] = new PDO($cfg['dsn'], $credentials['user'], $credentials['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function setApplicationContext(PDO $pdo, string $token, string $role): void
    {
        $dbRole = match ($role) {
            'paciente' => 'rbac_paciente', 'medico' => 'rbac_medico', 'administrador' => 'rbac_administrador',
            default => throw new RuntimeException('Perfil no reconocido.'),
        };
        $pdo->exec('SET ROLE ' . $dbRole);
        $q = $pdo->prepare("SELECT set_config('app.session_token', ?, false)");
        $q->execute([$token]);
        $q = $pdo->query('SELECT app_user_id(),app_rol()')->fetch(PDO::FETCH_NUM);
        if (!$q || $q[0] === null || $q[1] !== $role) throw new RuntimeException('La sesión venció; inicia sesión otra vez.');
    }
}
