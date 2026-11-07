<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax']);
session_start();

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function allowed(PDO $pdo, string $role, string $permission): bool
{
    $query = $pdo->prepare(
        'SELECT COUNT(*) FROM rol_permiso rp JOIN roles r USING (id_rol) '
        . 'JOIN permisos p USING (id_permiso) WHERE r.codigo = ? AND p.codigo = ?'
    );
    $query->execute([$role, $permission]);
    return (int)$query->fetchColumn() > 0;
}

function requirePermission(PDO $pdo, string $role, string $permission): void
{
    if (!allowed($pdo, $role, $permission)) {
        http_response_code(403);
        throw new RuntimeException('Tu rol no tiene permiso para realizar esta acción.');
    }
}

function tariffForIncome(PDO $pdo, float $income): ?int
{
    $query = $pdo->prepare('SELECT id_tarifa FROM tarifa WHERE ingreso_min <= ? AND ingreso_max >= ? ORDER BY ingreso_min DESC LIMIT 1');
    $query->execute([$income, $income]);
    $id = $query->fetchColumn();
    return $id === false ? null : (int)$id;
}

function redirectHome(): never
{
    header('Location: /');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

if (isset($_GET['salir'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    redirectHome();
}

$pdo = null;
$error = '';
$message = '';
$agenda = [];
$completedAppointments = [];
$patients = [];
$types = [];
$specialties = [];
$doctors = [];
$clinics = [];
$action = (string)($_POST['accion'] ?? '');

try {
    $pdo = Database::getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            throw new RuntimeException('El formulario venció. Recarga la página e inténtalo otra vez.');
        }

        if (empty($_SESSION['user_id'])) {
            if ($action === 'login') {
                $query = $pdo->prepare(
                    'SELECT u.id_usuario, u.password_hash, u.activo, r.codigo AS rol, '
                    . "COALESCE(p.nombre1 || ' ' || p.apellido1, m.nombre1 || ' ' || m.apellido1, u.username) AS nombre "
                    . 'FROM usuarios u JOIN roles r USING (id_rol) '
                    . 'LEFT JOIN pacientes p USING (id_usuario) LEFT JOIN medicos m USING (id_usuario) '
                    . 'WHERE u.username = ?'
                );
                $query->execute([strtolower(trim((string)($_POST['username'] ?? '')))]);
                $user = $query->fetch();
                if (!$user || !$user['activo'] || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                    throw new RuntimeException('Usuario o contraseña incorrectos.');
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id_usuario'];
                $_SESSION['role'] = (string)$user['rol'];
                $_SESSION['name'] = (string)$user['nombre'];
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                redirectHome();
            }

            if ($action === 'register') {
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['password_confirm'] ?? '');
                $document = trim((string)($_POST['num_doc'] ?? ''));
                $documentType = (int)($_POST['id_tipo_doc'] ?? 0);
                $income = filter_var($_POST['ingreso_mensual'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/', $username)) {
                    throw new RuntimeException('El usuario debe tener 4 a 50 letras; puede incluir punto, guion o guion bajo.');
                }
                if (strlen($password) < 8 || $password !== $confirm) {
                    throw new RuntimeException('La contraseña debe tener al menos 8 caracteres y coincidir con su confirmación.');
                }
                if ($income === false || $income < 0) {
                    throw new RuntimeException('El ingreso mensual debe ser cero o un número positivo.');
                }
                foreach (['nombre1', 'nombre2', 'apellido1', 'apellido2'] as $field) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) {
                        throw new RuntimeException('Los nombres y apellidos solo pueden contener letras, espacios y guiones.');
                    }
                }
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc = ?');
                $typeQuery->execute([$documentType]);
                $type = (string)$typeQuery->fetchColumn();
                $documentPattern = in_array($type, ['CC', 'TI', 'RC'], true)
                    ? '/^[0-9]{1,10}$/'
                    : '/^[A-Za-z0-9]{1,20}$/';
                if ($type === '' || !preg_match($documentPattern, $document)) {
                    throw new RuntimeException('Revisa el tipo y el formato del documento. CC, TI y RC usan solo números.');
                }
                $birth = (string)($_POST['fecha_nacimiento'] ?? '');
                $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
                if (!$birthDate || $birthDate->format('Y-m-d') !== $birth || $birth > date('Y-m-d')) {
                    throw new RuntimeException('Indica una fecha de nacimiento válida.');
                }
                $tariffId = tariffForIncome($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso declarado no coincide con una categoría tarifaria.');

                $pdo->beginTransaction();
                $roleQuery = $pdo->query("SELECT id_rol FROM roles WHERE codigo = 'paciente'");
                $roleId = (int)$roleQuery->fetchColumn();
                $userQuery = $pdo->prepare('INSERT INTO usuarios (username, password_hash, id_rol) VALUES (?, ?, ?) RETURNING id_usuario');
                $userQuery->execute([$username, password_hash($password, PASSWORD_DEFAULT), $roleId]);
                $userId = (int)$userQuery->fetchColumn();
                $patientQuery = $pdo->prepare(
                    'INSERT INTO pacientes (id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id_paciente'
                );
                $patientQuery->execute([
                    $userId, $documentType, $document,
                    trim((string)$_POST['nombre1']), trim((string)($_POST['nombre2'] ?? '')) ?: null,
                    trim((string)$_POST['apellido1']), trim((string)($_POST['apellido2'] ?? '')) ?: null,
                    trim((string)($_POST['sexo'] ?? '')) ?: null, $birth,
                    trim((string)($_POST['telefono'] ?? '')) ?: null,
                    trim((string)($_POST['correo'] ?? '')) ?: null,
                    trim((string)($_POST['direccion'] ?? '')) ?: null, $income,
                    $tariffId,
                ]);
                $patientId = (int)$patientQuery->fetchColumn();
                $history = $pdo->prepare('INSERT INTO historial (id_paciente) VALUES (?)');
                $history->execute([$patientId]);
                $pdo->commit();
                $message = 'Cuenta creada. Tu afiliación está Pendiente hasta que la EPS verifique el pago externo.';
            }
        } else {
            $role = (string)$_SESSION['role'];
            if ($action === 'reservar_cita' && $role === 'paciente') {
                requirePermission($pdo, $role, 'reservar_cita');
                $query = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE id_usuario=? AND estado_afiliacion='Activo'");
                $query->execute([$_SESSION['user_id']]);
                $patientId = $query->fetchColumn();
                if (!$patientId) throw new RuntimeException('La afiliación debe estar Activa para reservar.');
                $query = $pdo->prepare("SELECT COUNT(*) FROM horario h JOIN estado e USING(id_estado) JOIN medicos m USING(id_medico) JOIN servicio s ON s.id_especialidad=m.id_especialidad WHERE h.id_horario=? AND s.id_servicio=? AND e.ambito='horario' AND e.nombre='Disponible' AND (h.fecha+h.hora_inicio)>CURRENT_TIMESTAMP");
                $query->execute([(int)($_POST['id_horario'] ?? 0), (int)($_POST['id_servicio'] ?? 0)]);
                if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Ese horario ya no está disponible.');
                $query = $pdo->prepare("INSERT INTO cita (id_paciente,id_servicio,id_horario,id_estado) SELECT ?,?, ?,id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'");
                $query->execute([$patientId,(int)($_POST['id_servicio'] ?? 0),(int)($_POST['id_horario'] ?? 0)]);
                $message = 'Cita reservada correctamente.';
            } elseif ($action === 'guardar_resultado' && $role === 'medico') {
                requirePermission($pdo, $role, 'registrar_resultado');
                $appointmentId = (int)($_POST['id_cita'] ?? 0);
                $query = $pdo->prepare("SELECT m.id_medico FROM medicos m JOIN cita c ON c.id_cita=? JOIN horario h USING(id_horario) JOIN estado ec ON ec.id_estado=c.id_estado AND ec.ambito='cita' AND ec.nombre='Programada' WHERE m.id_usuario=? AND m.id_medico=h.id_medico");
                $query->execute([$appointmentId,$_SESSION['user_id']]);
                $doctorId = $query->fetchColumn();
                $result = trim((string)($_POST['resultado'] ?? ''));
                if (!$doctorId) throw new RuntimeException('La cita ya no está pendiente o no pertenece a tu agenda.');
                if (strlen($result) < 3) throw new RuntimeException('Escribe un resultado de al menos 3 caracteres.');
                $pdo->beginTransaction();
                $query = $pdo->prepare('INSERT INTO resultado_consulta (id_cita,id_medico,resultado) VALUES (?,?,?) ON CONFLICT (id_cita) DO UPDATE SET resultado=EXCLUDED.resultado, fecha_registro=CURRENT_TIMESTAMP');
                $query->execute([$appointmentId,$doctorId,$result]);
                $query = $pdo->prepare("UPDATE cita SET id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Atendida') WHERE id_cita=?");
                $query->execute([$appointmentId]);
                $pdo->commit();
                $message = 'Resultado de atención guardado.';
            } elseif ($action === 'aprobar_afiliacion' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_pacientes');
                $state = (string)($_POST['estado_afiliacion'] ?? '');
                if (!in_array($state, ['Pendiente','Activo','Inactivo'], true)) throw new RuntimeException('Selecciona un estado de afiliación válido.');
                $query = $pdo->prepare('UPDATE pacientes SET estado_afiliacion=? WHERE id_paciente=?');
                $query->execute([$state,(int)($_POST['id_paciente'] ?? 0)]);
                $message = 'Afiliación actualizada.';
            } elseif ($action === 'crear_paciente_admin' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_pacientes');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $income = filter_var($_POST['ingreso_mensual'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/', $username) || strlen($password) < 8 || $income === false || $income < 0) {
                    throw new RuntimeException('Revisa el usuario, la contraseña y el ingreso mensual.');
                }
                foreach (['nombre1','nombre2','apellido1','apellido2'] as $field) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) {
                        throw new RuntimeException('Los nombres y apellidos solo pueden contener letras.');
                    }
                }
                $birth = (string)($_POST['fecha_nacimiento'] ?? '');
                $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
                if (!$birthDate || $birthDate->format('Y-m-d') !== $birth || $birth > date('Y-m-d')) throw new RuntimeException('Fecha de nacimiento inválida.');
                $typeId = (int)($_POST['id_tipo_doc'] ?? 0);
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?');
                $typeQuery->execute([$typeId]);
                $docType = (string)$typeQuery->fetchColumn();
                $doc = trim((string)($_POST['num_doc'] ?? ''));
                $docPattern = in_array($docType, ['CC','TI','RC'], true) ? '/^[0-9]{1,10}$/' : '/^[A-Za-z0-9]{1,20}$/';
                if ($docType === '' || !preg_match($docPattern, $doc)) throw new RuntimeException('Revisa el tipo y el formato del documento.');
                $tariffId = tariffForIncome($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso no coincide con una categoría tarifaria.');
                $pdo->beginTransaction();
                $roleId = (int)$pdo->query("SELECT id_rol FROM roles WHERE codigo='paciente'")->fetchColumn();
                $query = $pdo->prepare('INSERT INTO usuarios(username,password_hash,id_rol) VALUES(?,?,?) RETURNING id_usuario');
                $query->execute([$username,password_hash($password,PASSWORD_DEFAULT),$roleId]);
                $userId = (int)$query->fetchColumn();
                $query = $pdo->prepare('INSERT INTO pacientes(id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa,estado_afiliacion) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'Pendiente\') RETURNING id_paciente');
                $query->execute([$userId,$typeId,$doc,trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,$birth,trim((string)($_POST['telefono']??''))?:null,trim((string)($_POST['correo']??''))?:null,trim((string)($_POST['direccion']??''))?:null,$income,$tariffId]);
                $patientId = (int)$query->fetchColumn();
                $pdo->prepare('INSERT INTO historial(id_paciente) VALUES(?)')->execute([$patientId]);
                $pdo->commit();
                $message = 'Afiliado creado con afiliación Pendiente. La categoría se asignó según el ingreso.';
            } elseif ($action === 'actualizar_paciente' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_pacientes');
                $patientId = (int)($_POST['id_paciente'] ?? 0);
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $income = filter_var($_POST['ingreso_mensual'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/', $username) || $income === false || $income < 0) throw new RuntimeException('Revisa el usuario y el ingreso mensual.');
                foreach (['nombre1','nombre2','apellido1','apellido2'] as $field) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) throw new RuntimeException('Los nombres y apellidos solo pueden contener letras.');
                }
                $birth = (string)($_POST['fecha_nacimiento'] ?? '');
                $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
                if (!$birthDate || $birthDate->format('Y-m-d') !== $birth || $birth > date('Y-m-d')) throw new RuntimeException('Fecha de nacimiento inválida.');
                $typeId = (int)($_POST['id_tipo_doc'] ?? 0);
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?'); $typeQuery->execute([$typeId]);
                $docType = (string)$typeQuery->fetchColumn(); $doc = trim((string)($_POST['num_doc'] ?? ''));
                $docPattern = in_array($docType, ['CC','TI','RC'], true) ? '/^[0-9]{1,10}$/' : '/^[A-Za-z0-9]{1,20}$/';
                if ($docType === '' || !preg_match($docPattern, $doc)) throw new RuntimeException('Revisa el tipo y el formato del documento.');
                $tariffId = tariffForIncome($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso no coincide con una categoría tarifaria.');
                $pdo->beginTransaction();
                $userIdQuery = $pdo->prepare('SELECT id_usuario FROM pacientes WHERE id_paciente=?'); $userIdQuery->execute([$patientId]);
                $userId = $userIdQuery->fetchColumn();
                if (!$userId) throw new RuntimeException('No se encontró el afiliado.');
                $pdo->prepare('UPDATE usuarios SET username=? WHERE id_usuario=?')->execute([$username,$userId]);
                $query = $pdo->prepare('UPDATE pacientes SET id_tipo_doc=?,num_doc=?,nombre1=?,nombre2=?,apellido1=?,apellido2=?,fecha_nacimiento=?,telefono=?,correo=?,direccion=?,ingreso_mensual=?,id_tarifa=? WHERE id_paciente=?');
                $query->execute([$typeId,$doc,trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,$birth,trim((string)($_POST['telefono']??''))?:null,trim((string)($_POST['correo']??''))?:null,trim((string)($_POST['direccion']??''))?:null,$income,$tariffId,$patientId]);
                $pdo->commit();
                $message = 'Datos del afiliado actualizados. La categoría se recalculó según el ingreso.';
            } elseif ($action === 'crear_medico' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_medicos');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/',$username) || strlen($password)<8) throw new RuntimeException('Usuario o contraseña inválidos.');
                foreach (['nombre1','nombre2','apellido1','apellido2'] as $field) {
                    $value = trim((string)($_POST[$field]??''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) throw new RuntimeException('El nombre y el apellido solo pueden contener letras.');
                }
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?');
                $typeQuery->execute([(int)($_POST['id_tipo_doc'] ?? 0)]);
                $docType = (string)$typeQuery->fetchColumn();
                $doc = trim((string)($_POST['num_doc'] ?? ''));
                $docPattern = in_array($docType, ['CC','TI','RC'], true) ? '/^[0-9]{1,10}$/' : '/^[A-Za-z0-9]{1,20}$/';
                if ($docType === '' || !preg_match($docPattern, $doc)) throw new RuntimeException('Revisa el tipo y el formato del documento médico.');
                $pdo->beginTransaction();
                $idRole = (int)$pdo->query("SELECT id_rol FROM roles WHERE codigo='medico'")->fetchColumn();
                $query = $pdo->prepare('INSERT INTO usuarios (username,password_hash,id_rol) VALUES (?,?,?) RETURNING id_usuario');
                $query->execute([$username,password_hash($password,PASSWORD_DEFAULT),$idRole]);
                $userId = (int)$query->fetchColumn();
                $query = $pdo->prepare('INSERT INTO medicos (id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,telefono,correo,id_especialidad,id_consultorio) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $query->execute([$userId,(int)$_POST['id_tipo_doc'],trim((string)$_POST['num_doc']),trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,trim((string)($_POST['telefono']??''))?:null,trim((string)($_POST['correo']??''))?:null,(int)$_POST['id_especialidad'],($_POST['id_consultorio']??'')===''?null:(int)$_POST['id_consultorio']]);
                $pdo->commit();
                $message = 'Perfil médico creado.';
            } elseif ($action === 'crear_horario' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_horarios');
                $query = $pdo->prepare("INSERT INTO horario (id_medico,fecha,hora_inicio,hora_fin,id_estado) SELECT ?,?,?,?,id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible'");
                $query->execute([(int)$_POST['id_medico'],(string)$_POST['fecha'],(string)$_POST['hora_inicio'],(string)$_POST['hora_fin']]);
                $message = 'Horario agregado.';
            } else {
                throw new RuntimeException('Esa acción no está disponible para tu perfil.');
            }
        }
    }
} catch (Throwable $exception) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    $error = $exception instanceof PDOException
        ? 'No se pudo completar la operación. Verifica los datos y que hayas instalado proyecto.sql.'
        : $exception->getMessage();
}

if (empty($_SESSION['user_id'])) {
    try {
        $pdo = Database::getConnection();
        $types = $pdo->query('SELECT id_tipo_doc,sigla,nombre FROM tipo_documento ORDER BY sigla')->fetchAll();
    } catch (Throwable $exception) {
        http_response_code(503);
        $types = [];
        $error = $error ?: 'No se pudo conectar con PostgreSQL. Revisa config.php y que el servidor esté activo.';
    }
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Ingreso</title><link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page">
    <header class="site-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><button type="button" id="help-open" class="help-trigger">Atención al usuario</button></header>
    <main class="auth-layout"><section class="auth-illustration"><img src="/assets/ilustracion-familia.png" alt="Familia reunida"><div><p class="eyebrow">EPS Villavicencio</p><h1>Tu salud, más cerca.</h1><p>Consulta tus citas y encuentra los servicios disponibles.</p></div></section>
    <section class="auth-panel"><p class="eyebrow">Portal de afiliados</p><h2>Bienvenido</h2><p>Ingresa o crea una cuenta para solicitar una afiliación.</p><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($message):?><div class="notice"><?=h($message)?></div><?php endif?>
    <div class="tabs"><button type="button" class="tab active" data-tab="login">Iniciar sesión</button><button type="button" class="tab" data-tab="register">Crear cuenta</button></div>
    <form method="post" class="auth-form tab-panel active" data-panel="login"><input type="hidden" name="accion" value="login"><?=csrfField()?><label>Usuario</label><input name="username" required autocomplete="username"><label>Contraseña</label><input name="password" type="password" required autocomplete="current-password"><button class="button primary">Ingresar</button></form>
    <form method="post" class="auth-form tab-panel validate-live" data-panel="register" data-validate-form><input type="hidden" name="accion" value="register"><?=csrfField()?><div class="form-grid"><div class="field full"><label>Usuario (4 a 50 letras, sin números)</label><input name="username" pattern="[A-Za-z][A-Za-z._-]{3,49}" required data-rule="username"></div><div class="field"><label>Contraseña (mínimo 8 caracteres)</label><input name="password" type="password" minlength="8" required data-rule="password"></div><div class="field"><label>Confirmar contraseña</label><input name="password_confirm" type="password" minlength="8" required data-rule="password-confirm"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>"><?=h($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número de documento</label><input name="num_doc" maxlength="20" required data-document-number></div><div class="field"><label>Primer nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name"></div><div class="field"><label>Primer apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name"></div><div class="field"><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" max="<?=date('Y-m-d')?>" required></div><div class="field"><label>Ingreso mensual</label><input type="number" name="ingreso_mensual" min="0" step="1000" required></div><div class="field"><label>Teléfono</label><input name="telefono"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field full"><label>Dirección</label><input name="direccion"></div></div><button class="button primary">Crear solicitud</button><p class="form-footnote">La afiliación quedará Pendiente mientras la EPS verifica el pago por fuera del portal.</p></form><footer>Aplicación académica · Uso local</footer></section></main>
    <dialog id="help-dialog" class="help-dialog"><button type="button" class="dialog-close" id="help-close" aria-label="Cerrar">×</button><p class="eyebrow">Ayuda del portal</p><h2>¿Necesitas ayuda?</h2><p>Para consultar una cita o el estado de afiliación, inicia sesión con tu usuario.</p><p>Si acabas de registrarte, tu afiliación queda Pendiente hasta que administración confirme el pago externo.</p><p>Para problemas con tu cuenta, comunícate directamente con el administrador de la EPS.</p></dialog>
    <script>
    const helpDialog=document.getElementById('help-dialog');
    document.getElementById('help-open')?.addEventListener('click',()=>helpDialog.showModal());
    document.getElementById('help-close')?.addEventListener('click',()=>helpDialog.close());

    helpDialog?.addEventListener('click',event=>{if(event.target===helpDialog)helpDialog.close();});
    document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===button.dataset.tab));}));
    document.querySelectorAll('[data-validate-form]').forEach(form=>{
      const type=form.querySelector('[data-document-type]'), number=form.querySelector('[data-document-number]');
      const fields=[...form.querySelectorAll('input,select,textarea')].filter(field=>field.type!=='hidden');
      function errorFor(field){
        const value=field.value.trim();
        if(field.required&&!value)return 'Completa este campo.';
        if(!value)return '';
        if(field.dataset.rule==='username'&&!/^[A-Za-z][A-Za-z._-]{3,49}$/.test(value))return 'Usa 4 a 50 letras; no escribas números.';
        if(field.dataset.rule==='name'&&!/^[\p{L}\p{M} .'-]+$/u.test(value))return 'Escribe solo letras, espacios o guiones.';
        if(field.dataset.rule==='password'&&value.length<8)return 'Escribe al menos 8 caracteres.';
        if(field.dataset.rule==='password-confirm'&&value!==form.querySelector('[name="password"]')?.value)return 'Las contraseñas deben coincidir.';
        if(field===number&&type){const sigla=type.options[type.selectedIndex]?.textContent.trim().slice(0,2);const numeric=['CC','TI','RC'].includes(sigla);if(numeric?!/^[0-9]{1,10}$/.test(value):!/^[A-Za-z0-9]{1,20}$/.test(value))return numeric?'Este documento debe tener solo números (máximo 10).':'Usa letras o números (máximo 20).';}
        if(field.type==='email'&&field.validity.typeMismatch)return 'Escribe un correo válido.';
        if(field.type==='number'&&Number(value)<0)return 'El valor no puede ser negativo.';
        if(field.type==='date'&&field.max&&value>field.max)return 'La fecha no puede ser futura.';
        if(field.validity.patternMismatch)return 'Revisa el formato indicado para este campo.';
        return '';
      }
      function paint(field){const box=field.closest('.field');if(!box)return;const message=errorFor(field);box.classList.toggle('invalid',!!message);box.classList.toggle('valid',!message&&!!field.value);let note=box.querySelector('.validation-message');if(message){if(!note){note=document.createElement('small');note.className='validation-message';box.append(note);}note.textContent=message;}else if(note)note.remove();}
      fields.forEach(field=>{field.addEventListener('input',()=>{paint(field);if(field.name==='password')paint(form.querySelector('[name="password_confirm"]'));});field.addEventListener('change',()=>{paint(field);if(field===type&&number)paint(number);});field.addEventListener('blur',()=>paint(field));});
      form.addEventListener('submit',event=>{fields.forEach(paint);if(form.querySelector('.field.invalid')){event.preventDefault();form.querySelector('.field.invalid input,.field.invalid select')?.focus();}});
    });
    <?php if($action==='register'):?>document.querySelector('[data-tab="register"]').click();<?php endif?>
    </script></body></html><?php exit;
}

$role = (string)$_SESSION['role'];
$name = (string)$_SESSION['name'];
$dashboardError = '';
try {
    if ($role === 'paciente') {
        $query = $pdo->prepare('SELECT p.id_paciente,p.estado_afiliacion,t.nombre AS categoria,t.porcentaje_cobro FROM pacientes p LEFT JOIN tarifa t USING(id_tarifa) WHERE p.id_usuario=?');
        $query->execute([$_SESSION['user_id']]); $patient = $query->fetch();
        $query = $pdo->query("SELECT h.id_horario,h.fecha,h.hora_inicio,m.nombre1||' '||m.apellido1 AS medico,e.nombre AS especialidad,e.id_especialidad FROM horario h JOIN estado st USING(id_estado) JOIN medicos m USING(id_medico) JOIN especialidad e USING(id_especialidad) WHERE st.ambito='horario' AND st.nombre='Disponible' AND (h.fecha+h.hora_inicio)>CURRENT_TIMESTAMP ORDER BY h.fecha,h.hora_inicio"); $slots = $query->fetchAll();
        $services = $pdo->query('SELECT id_servicio,nombre,costo_base,id_especialidad FROM servicio ORDER BY nombre')->fetchAll();
        $query = $pdo->prepare("SELECT c.id_cita,c.valor_cobrado,e.nombre AS estado,h.fecha,h.hora_inicio,s.nombre AS servicio,m.nombre1||' '||m.apellido1 AS medico,r.resultado FROM cita c JOIN estado e USING(id_estado) JOIN horario h USING(id_horario) JOIN servicio s USING(id_servicio) JOIN medicos m USING(id_medico) LEFT JOIN resultado_consulta r USING(id_cita) WHERE c.id_paciente=? ORDER BY h.fecha DESC,h.hora_inicio DESC");
        $query->execute([$patient['id_paciente']]); $appointments = $query->fetchAll();
        $query = $pdo->prepare('SELECT alergias,grupo_sanguineo,antecedentes FROM historial WHERE id_paciente=?'); $query->execute([$patient['id_paciente']]); $history = $query->fetch();
    } elseif ($role === 'medico') {
        $query = $pdo->prepare("SELECT c.id_cita,e.nombre AS estado,h.fecha,h.hora_inicio,p.nombre1||' '||p.apellido1 AS paciente,p.num_doc,p.telefono,s.nombre AS servicio,r.resultado,hist.alergias,hist.antecedentes,hist.grupo_sanguineo FROM medicos m JOIN horario h USING(id_medico) JOIN cita c USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado JOIN pacientes p USING(id_paciente) JOIN servicio s USING(id_servicio) JOIN historial hist USING(id_paciente) LEFT JOIN resultado_consulta r USING(id_cita) WHERE m.id_usuario=? AND e.nombre='Programada' ORDER BY h.fecha,h.hora_inicio");
        $query->execute([$_SESSION['user_id']]); $agenda = $query->fetchAll();
        $query = $pdo->prepare("SELECT c.id_cita,h.fecha,h.hora_inicio,p.nombre1||' '||p.apellido1 AS paciente,s.nombre AS servicio,r.resultado FROM medicos m JOIN horario h USING(id_medico) JOIN cita c USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado JOIN pacientes p USING(id_paciente) JOIN servicio s USING(id_servicio) JOIN resultado_consulta r USING(id_cita) WHERE m.id_usuario=? AND e.nombre='Atendida' ORDER BY h.fecha DESC,h.hora_inicio DESC LIMIT 30");
        $query->execute([$_SESSION['user_id']]); $completedAppointments = $query->fetchAll();
    } else {
        $patients = $pdo->query('SELECT p.id_paciente,p.id_usuario,p.id_tipo_doc,p.num_doc,p.nombre1,p.nombre2,p.apellido1,p.apellido2,p.fecha_nacimiento,p.telefono,p.correo,p.direccion,p.ingreso_mensual,p.estado_afiliacion,u.username,t.sigla,tr.nombre AS categoria FROM pacientes p JOIN usuarios u USING(id_usuario) JOIN tipo_documento t USING(id_tipo_doc) LEFT JOIN tarifa tr USING(id_tarifa) ORDER BY p.apellido1,p.nombre1')->fetchAll();
        $types = $pdo->query('SELECT id_tipo_doc,sigla FROM tipo_documento ORDER BY sigla')->fetchAll();
        $specialties = $pdo->query('SELECT id_especialidad,nombre FROM especialidad ORDER BY nombre')->fetchAll();
        $doctors = $pdo->query('SELECT id_medico,nombre1,apellido1 FROM medicos ORDER BY apellido1')->fetchAll();
        $clinics = $pdo->query('SELECT id_consultorio,nombre FROM consultorio ORDER BY nombre')->fetchAll();
    }
} catch (Throwable $exception) {
    $dashboardError = 'No se pudieron cargar los datos del panel: ' . $exception->getMessage();
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Panel</title><link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="stylesheet" href="/assets/app.css"></head><body class="app-page"><header class="app-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><div class="user-menu"><button type="button" id="help-open" class="help-trigger">Atención al usuario</button><?=h($name)?> · <?=h(ucfirst($role))?> <a class="button secondary compact" href="/?salir=1">Salir</a></div></header><main class="container"><p class="eyebrow"><?=h(strtoupper($role))?></p><h1><?= $role==='paciente'?'Mi portal':($role==='medico'?'Mi agenda':'Administración') ?></h1><?php if($message):?><div class="notice"><?=h($message)?></div><?php endif?><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($dashboardError):?><div class="notice error"><?=h($dashboardError)?></div><?php endif?>
<?php if($role==='paciente'):?><section class="panel"><h2>Estado de afiliación: <?=h($patient['estado_afiliacion']??'Sin perfil')?></h2><p>Categoría: <strong><?=h($patient['categoria']??'Sin categoría')?></strong>.</p></section><div class="two-column"><section class="panel"><h2>Agendar una cita</h2><?php if(($patient['estado_afiliacion']??'')!=='Activo'):?><p class="notice warning">Tu afiliación está pendiente de revisión por la EPS.</p><?php elseif(!$slots):?><p>No hay horarios disponibles en este momento.</p><?php else:?><form method="post" class="appointment-form"><?=csrfField()?><input type="hidden" name="accion" value="reservar_cita"><label>Servicio</label><select name="id_servicio" id="service-select" required><option value="" selected disabled>Selecciona un servicio</option><?php foreach($services as $s):?><option value="<?=h($s['id_servicio'])?>" data-cost="<?=h($s['costo_base'])?>" data-specialty="<?=h($s['id_especialidad'])?>"><?=h($s['nombre'])?></option><?php endforeach?></select><p class="copay-note" id="copay-info" data-percentage="<?=h($patient['porcentaje_cobro']??'0')?>">El copago se calcula después de elegir el servicio.</p><label>Horario</label><select name="id_horario" id="slot-select" required disabled><option value="" selected disabled>Primero selecciona un servicio</option><?php foreach($slots as $s):?><option value="<?=h($s['id_horario'])?>" data-specialty="<?=h($s['id_especialidad'])?>"><?=h($s['fecha'].' '.substr((string)$s['hora_inicio'],0,5).' · '.$s['medico'].' · '.$s['especialidad'])?></option><?php endforeach?></select><button class="button primary">Reservar cita</button></form><?php endif?></section><section class="panel"><h2>Mi historia clínica</h2><p><strong>Alergias:</strong> <?=h($history['alergias']??'Sin registro')?></p><p><strong>Grupo sanguíneo:</strong> <?=h($history['grupo_sanguineo']??'Sin registro')?></p><p><strong>Antecedentes:</strong> <?=h($history['antecedentes']??'Sin registro')?></p></section></div><section class="panel"><h2>Mis citas</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Servicio</th><th>Valor</th><th>Estado</th><th>Resultado</th></tr></thead><tbody><?php foreach($appointments as $a):?><tr><td><?=h($a['fecha'])?></td><td><?=h(substr((string)$a['hora_inicio'],0,5))?></td><td><?=h($a['medico'])?></td><td><?=h($a['servicio'])?></td><td>$<?=number_format((float)$a['valor_cobrado'],0,',','.')?></td><td><?=h($a['estado'])?></td><td><?=h($a['resultado']??'Pendiente')?></td></tr><?php endforeach?></tbody></table></div></section>
<?php elseif($role==='medico'):?>
<section class="panel">
    <div class="section-heading"><div><h2>Citas pendientes de atención</h2><p>Al guardar el resultado, la cita pasa a Atendida y sale de esta lista.</p></div><span class="count-badge"><?=count($agenda)?></span></div>
    <?php if (!$agenda): ?><p class="empty-state">No tienes citas pendientes en tu agenda.</p><?php else: ?>
    <div class="table-wrap"><table class="agenda-table"><thead><tr><th>Fecha y hora</th><th>Paciente</th><th>Documento</th><th>Servicio</th><th>Historia resumida</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach($agenda as $a):?><tr><td><?=h($a['fecha'].' · '.substr((string)$a['hora_inicio'],0,5))?></td><td><?=h($a['paciente'])?></td><td><?=h($a['num_doc'])?></td><td><?=h($a['servicio'])?></td><td>Alergias: <?=h($a['alergias']?:'—')?>; antecedentes: <?=h($a['antecedentes']?:'—')?>; grupo: <?=h($a['grupo_sanguineo']?:'—')?></td><td><form method="post" class="result-form"><?=csrfField()?><input type="hidden" name="accion" value="guardar_resultado"><input type="hidden" name="id_cita" value="<?=h($a['id_cita'])?>"><textarea name="resultado" minlength="3" required placeholder="Escribe el resultado de la atención"><?=h($a['resultado']??'')?></textarea><button class="button primary compact">Guardar y cerrar atención</button></form></td></tr><?php endforeach?>
    </tbody></table></div><?php endif?>
</section>
<details class="panel history-panel"><summary>Atenciones registradas (<?=count($completedAppointments)?>)</summary><p>Estas citas ya se marcaron como Atendidas; se conservan como historial y no vuelven a la lista pendiente.</p><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Servicio</th><th>Resultado</th></tr></thead><tbody><?php foreach($completedAppointments as $a):?><tr><td><?=h($a['fecha'])?></td><td><?=h(substr((string)$a['hora_inicio'],0,5))?></td><td><?=h($a['paciente'])?></td><td><?=h($a['servicio'])?></td><td><?=h($a['resultado'])?></td></tr><?php endforeach?></tbody></table></div></details>
<?php else: ?>
<section class="panel">
    <div class="section-heading"><div><h2>Solicitudes y afiliaciones</h2><p>Activa una afiliación al confirmar el pago. La tarifa se asigna automáticamente según el ingreso.</p></div><span class="count-badge"><?=count($patients)?></span></div>
    <div class="table-wrap"><table><thead><tr><th>Afiliado</th><th>Documento</th><th>Ingreso</th><th>Categoría</th><th>Estado</th><th>Actualizar estado</th><th>Datos</th></tr></thead><tbody>
    <?php foreach($patients as $p):?><tr><td><?=h($p['nombre1'].' '.$p['apellido1'])?><small class="table-subtitle"><?=h($p['username'])?></small></td><td><?=h($p['sigla'].' '.$p['num_doc'])?></td><td>$<?=number_format((float)$p['ingreso_mensual'],0,',','.')?></td><td><?=h($p['categoria']??'Sin categoría')?></td><td><span class="status-tag status-<?=h(strtolower($p['estado_afiliacion']))?>"><?=h($p['estado_afiliacion'])?></span></td><td><form method="post" class="inline-form"><?=csrfField()?><input type="hidden" name="accion" value="aprobar_afiliacion"><input type="hidden" name="id_paciente" value="<?=h($p['id_paciente'])?>"><select name="estado_afiliacion" aria-label="Estado de afiliación"><option value="Pendiente" <?=$p['estado_afiliacion']==='Pendiente'?'selected':''?>>Pendiente</option><option value="Activo" <?=$p['estado_afiliacion']==='Activo'?'selected':''?>>Activo</option><option value="Inactivo" <?=$p['estado_afiliacion']==='Inactivo'?'selected':''?>>Inactivo</option></select><button class="button primary compact">Guardar</button></form></td><td><details class="edit-details"><summary>Editar afiliado</summary><form method="post" class="form-grid admin-edit-form" data-validate-form><?=csrfField()?><input type="hidden" name="accion" value="actualizar_paciente"><input type="hidden" name="id_paciente" value="<?=h($p['id_paciente'])?>"><div class="field"><label>Usuario</label><input name="username" value="<?=h($p['username'])?>" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" data-document-type><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>" <?=$p['id_tipo_doc']===$t['id_tipo_doc']?'selected':''?>><?=h($t['sigla'])?></option><?php endforeach?></select></div><div class="field"><label>Número</label><input name="num_doc" value="<?=h($p['num_doc'])?>" required maxlength="20" data-document-number></div><div class="field"><label>Primer nombre</label><input name="nombre1" data-rule="name" value="<?=h($p['nombre1'])?>" required></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name" value="<?=h($p['nombre2'])?>"></div><div class="field"><label>Primer apellido</label><input name="apellido1" data-rule="name" value="<?=h($p['apellido1'])?>" required></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name" value="<?=h($p['apellido2'])?>"></div><div class="field"><label>Nacimiento</label><input type="date" name="fecha_nacimiento" value="<?=h($p['fecha_nacimiento'])?>" required></div><div class="field"><label>Ingreso mensual</label><input type="number" name="ingreso_mensual" min="0" step="1000" value="<?=h($p['ingreso_mensual'])?>" required></div><div class="field"><label>Teléfono</label><input name="telefono" value="<?=h($p['telefono'])?>"></div><div class="field"><label>Correo</label><input type="email" name="correo" value="<?=h($p['correo'])?>"></div><div class="field full"><label>Dirección</label><input name="direccion" value="<?=h($p['direccion'])?>"></div><div class="field full"><button class="button primary compact">Guardar datos del afiliado</button></div></form></details></td></tr><?php endforeach?>
    </tbody></table></div>
</section>
<div class="two-column">
<section class="panel"><h2>Registrar afiliado</h2><p>La cuenta se crea con estado Pendiente. La categoría se obtiene del ingreso mensual.</p><form method="post" class="form-grid validate-live" data-validate-form><?=csrfField()?><input type="hidden" name="accion" value="crear_paciente_admin"><div class="field"><label>Usuario</label><input name="username" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"><small class="field-help">4–50 letras, sin números.</small></div><div class="field"><label>Contraseña inicial</label><input name="password" type="password" minlength="8" required data-rule="password"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>"><?=h($t['sigla'])?></option><?php endforeach?></select></div><div class="field"><label>Número de documento</label><input name="num_doc" required maxlength="20" data-document-number><small class="field-help">CC, TI y RC: solo números. CE y PA: letras y números.</small></div><div class="field"><label>Primer nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name"></div><div class="field"><label>Primer apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name"></div><div class="field"><label>Fecha de nacimiento</label><input name="fecha_nacimiento" type="date" max="<?=date('Y-m-d')?>" required></div><div class="field"><label>Ingreso mensual</label><input name="ingreso_mensual" type="number" min="0" step="1000" required></div><div class="field"><label>Teléfono</label><input name="telefono"></div><div class="field"><label>Correo</label><input name="correo" type="email"></div><div class="field full"><label>Dirección</label><input name="direccion"></div><div class="field full"><button class="button primary">Crear usuario afiliado</button></div></form></section>
<section class="panel"><h2>Crear cuenta médica</h2><form method="post" class="form-grid validate-live" data-validate-form><?=csrfField()?><input type="hidden" name="accion" value="crear_medico"><div class="field"><label>Usuario</label><input name="username" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"></div><div class="field"><label>Contraseña temporal</label><input name="password" type="password" minlength="8" required data-rule="password"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>"><?=h($t['sigla'])?></option><?php endforeach?></select></div><div class="field"><label>Número</label><input name="num_doc" required maxlength="20" data-document-number></div><div class="field"><label>Nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field"><label>Teléfono</label><input name="telefono"></div><div class="field"><label>Especialidad</label><select name="id_especialidad" required><?php foreach($specialties as $s):?><option value="<?=h($s['id_especialidad'])?>"><?=h($s['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Consultorio</label><select name="id_consultorio"><option value="">Sin asignar</option><?php foreach($clinics as $c):?><option value="<?=h($c['id_consultorio'])?>"><?=h($c['nombre'])?></option><?php endforeach?></select></div><div class="field full"><button class="button primary">Crear médico</button></div></form></section>
<section class="panel"><h2>Configurar horario</h2><form method="post" class="spaced-form"><?=csrfField()?><input type="hidden" name="accion" value="crear_horario"><label>Médico</label><select name="id_medico" required><?php foreach($doctors as $d):?><option value="<?=h($d['id_medico'])?>"><?=h($d['nombre1'].' '.$d['apellido1'])?></option><?php endforeach?></select><label>Fecha</label><input name="fecha" type="date" min="<?=date('Y-m-d')?>" required><div class="form-grid"><div class="field"><label>Inicio</label><input name="hora_inicio" type="time" required></div><div class="field"><label>Fin</label><input name="hora_fin" type="time" required></div></div><button class="button primary">Agregar horario</button></form></section>
</div><?php endif?></main>
<dialog id="help-dialog" class="help-dialog"><button type="button" class="dialog-close" id="help-close" aria-label="Cerrar">×</button><p class="eyebrow">Ayuda del portal</p><h2>¿Necesitas ayuda?</h2><p>Para consultar una cita o el estado de afiliación, inicia sesión con tu usuario.</p><p>Si acabas de registrarte, tu afiliación queda Pendiente hasta que administración confirme el pago externo.</p><p>Para problemas con tu cuenta, comunícate directamente con el administrador de la EPS.</p></dialog>
<script>
const helpDialog=document.getElementById('help-dialog');
document.getElementById('help-open')?.addEventListener('click',()=>helpDialog.showModal());
document.getElementById('help-close')?.addEventListener('click',()=>helpDialog.close());
const serviceSelect=document.getElementById('service-select');
const slotSelect=document.getElementById('slot-select');
const copayInfo=document.getElementById('copay-info');
if(serviceSelect&&slotSelect&&copayInfo){
  serviceSelect.addEventListener('change',()=>{
    const service=serviceSelect.options[serviceSelect.selectedIndex];
    const specialty=service.dataset.specialty;
    let available=0;
    [...slotSelect.options].forEach(option=>{
      if(!option.value)return;
      option.hidden=option.dataset.specialty!==specialty;
      option.disabled=option.hidden;
      if(!option.hidden)available++;
    });
    slotSelect.value='';
    slotSelect.disabled=available===0;
    slotSelect.options[0].textContent=available?'Selecciona un horario':'No hay horarios para este servicio';
    const copay=Math.min(Number(service.dataset.cost)*Number(copayInfo.dataset.percentage)/100,10000);
    const money=new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(copay);
    copayInfo.textContent=`Copago: ${money}`;
  });
}
helpDialog?.addEventListener('click',event=>{if(event.target===helpDialog)helpDialog.close();});
document.querySelectorAll('[data-validate-form]').forEach(form=>{
  const docType=form.querySelector('[data-document-type]');
  const docNumber=form.querySelector('[data-document-number]');
  const fields=[...form.querySelectorAll('input,select,textarea')].filter(input=>input.type!=='hidden');
  function messageFor(field){
    const value=field.value.trim();
    if(field.required&&!value)return 'Completa este campo.';
    if(!value)return '';
    if(field.dataset.rule==='username'&&!/^[A-Za-z][A-Za-z._-]{3,49}$/.test(value))return 'Usa 4 a 50 letras; no escribas números.';
    if(field.dataset.rule==='name'&&!/^[\p{L}\p{M} .'-]+$/u.test(value))return 'Escribe solo letras, espacios o guiones.';
    if(field.dataset.rule==='password'&&value.length<8)return 'Escribe al menos 8 caracteres.';
    if(field.dataset.rule==='password-confirm'&&value!==form.querySelector('[name="password"]')?.value)return 'Las contraseñas deben coincidir.';
    if(field===docNumber&&docType){const sigla=docType.options[docType.selectedIndex]?.textContent.trim().slice(0,2);const numeric=['CC','TI','RC'].includes(sigla);const ok=numeric?/^[0-9]{1,10}$/.test(value):/^[A-Za-z0-9]{1,20}$/.test(value);if(!ok)return numeric?'Este documento debe tener solo números (máximo 10).':'Usa letras o números (máximo 20).';}
    if(field.type==='email'&&field.validity.typeMismatch)return 'Escribe un correo válido.';
    if(field.type==='number'&&Number(value)<0)return 'El valor no puede ser negativo.';
    if(field.type==='date'&&field.max&&value>field.max)return 'La fecha no puede ser futura.';
    if(field.validity.patternMismatch)return 'Revisa el formato indicado para este campo.';
    return '';
  }
  function paint(field){if(!field.closest('.field'))return;const box=field.closest('.field');const msg=messageFor(field);box.classList.toggle('invalid',!!msg);box.classList.toggle('valid',!msg&&!!field.value);let note=box.querySelector('.validation-message');if(msg){if(!note){note=document.createElement('small');note.className='validation-message';box.append(note);}note.textContent=msg;}else if(note)note.remove();}
  fields.forEach(field=>{field.addEventListener('input',()=>{paint(field);if(field.name==='password')paint(form.querySelector('[name="password_confirm"]'));});field.addEventListener('change',()=>{paint(field);if(field===docType&&docNumber)paint(docNumber);});field.addEventListener('blur',()=>paint(field));});
  form.addEventListener('submit',event=>{fields.forEach(paint);if(form.querySelector('.field.invalid')){event.preventDefault();form.querySelector('.field.invalid input,.field.invalid select')?.focus();}});
});
document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===button.dataset.tab));}));
<?php if($action==='register'):?>document.querySelector('[data-tab="register"]')?.click();<?php endif?>
</script></body></html>
