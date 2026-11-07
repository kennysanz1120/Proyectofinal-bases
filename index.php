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
                    $pdo->query("SELECT id_tarifa FROM tarifa WHERE nombre='Categoría A'")->fetchColumn(),
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
                $query = $pdo->prepare("SELECT COUNT(*) FROM horario h JOIN estado e USING(id_estado) WHERE h.id_horario=? AND e.ambito='horario' AND e.nombre='Disponible' AND (h.fecha+h.hora_inicio)>CURRENT_TIMESTAMP");
                $query->execute([(int)($_POST['id_horario'] ?? 0)]);
                if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Ese horario ya no está disponible.');
                $query = $pdo->prepare("INSERT INTO cita (id_paciente,id_servicio,id_horario,id_estado) SELECT ?,?, ?,id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'");
                $query->execute([$patientId,(int)($_POST['id_servicio'] ?? 0),(int)($_POST['id_horario'] ?? 0)]);
                $message = 'Cita reservada correctamente.';
            } elseif ($action === 'guardar_resultado' && $role === 'medico') {
                requirePermission($pdo, $role, 'registrar_resultado');
                $appointmentId = (int)($_POST['id_cita'] ?? 0);
                $query = $pdo->prepare("SELECT m.id_medico FROM medicos m JOIN cita c ON c.id_cita=? JOIN horario h USING(id_horario) WHERE m.id_usuario=? AND m.id_medico=h.id_medico AND (h.fecha+h.hora_inicio)<=CURRENT_TIMESTAMP");
                $query->execute([$appointmentId,$_SESSION['user_id']]);
                $doctorId = $query->fetchColumn();
                $result = trim((string)($_POST['resultado'] ?? ''));
                if (!$doctorId || strlen($result) < 3) throw new RuntimeException('La cita debe pertenecer a tu agenda y el resultado debe tener al menos 3 caracteres.');
                $query = $pdo->prepare('INSERT INTO resultado_consulta (id_cita,id_medico,resultado) VALUES (?,?,?) ON CONFLICT (id_cita) DO UPDATE SET resultado=EXCLUDED.resultado, fecha_registro=CURRENT_TIMESTAMP');
                $query->execute([$appointmentId,$doctorId,$result]);
                $query = $pdo->prepare("UPDATE cita SET id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Atendida') WHERE id_cita=?");
                $query->execute([$appointmentId]);
                $message = 'Resultado de atención guardado.';
            } elseif ($action === 'aprobar_afiliacion' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_pacientes');
                $state = (string)($_POST['estado_afiliacion'] ?? '');
                $tariff = ($_POST['id_tarifa'] ?? '') === '' ? null : (int)$_POST['id_tarifa'];
                if (!in_array($state, ['Pendiente','Activo','Inactivo'], true) || ($state === 'Activo' && !$tariff)) throw new RuntimeException('Selecciona un estado y una tarifa válida para activar.');
                $query = $pdo->prepare('UPDATE pacientes SET estado_afiliacion=?, id_tarifa=COALESCE(?,id_tarifa) WHERE id_paciente=?');
                $query->execute([$state,$tariff,(int)($_POST['id_paciente'] ?? 0)]);
                $message = 'Afiliación actualizada.';
            } elseif ($action === 'crear_medico' && $role === 'administrador') {
                requirePermission($pdo, $role, 'gestionar_medicos');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/',$username) || strlen($password)<8) throw new RuntimeException('Usuario o contraseña inválidos.');
                foreach (['nombre1','apellido1'] as $field) {
                    if (!preg_match('/^[\p{L}\p{M} .\'-]+$/u',trim((string)($_POST[$field]??'')))) throw new RuntimeException('El nombre y el apellido solo pueden contener letras.');
                }
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
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Ingreso</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page">
    <header class="site-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><span class="header-help">Atención al usuario</span></header>
    <main class="auth-layout"><section class="auth-illustration"><img src="/assets/ilustracion-familia.png" alt="Familia reunida"><div><p class="eyebrow">EPS Villavicencio</p><h1>Tu salud, más cerca.</h1><p>Consulta tus citas y encuentra los servicios disponibles.</p></div></section>
    <section class="auth-panel"><p class="eyebrow">Portal de afiliados</p><h2>Bienvenido</h2><p>Ingresa o crea una cuenta para solicitar una afiliación.</p><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($message):?><div class="notice"><?=h($message)?></div><?php endif?>
    <div class="tabs"><button type="button" class="tab active" data-tab="login">Iniciar sesión</button><button type="button" class="tab" data-tab="register">Crear cuenta</button></div>
    <form method="post" class="auth-form tab-panel active" data-panel="login"><input type="hidden" name="accion" value="login"><?=csrfField()?><label>Usuario</label><input name="username" required autocomplete="username"><label>Contraseña</label><input name="password" type="password" required autocomplete="current-password"><button class="button primary">Ingresar</button></form>
    <form method="post" class="auth-form tab-panel" data-panel="register"><input type="hidden" name="accion" value="register"><?=csrfField()?><div class="form-grid"><div class="field full"><label>Usuario (sin números)</label><input name="username" pattern="[A-Za-z][A-Za-z._-]{3,49}" required></div><div class="field"><label>Contraseña (mínimo 8 caracteres)</label><input name="password" type="password" minlength="8" required></div><div class="field"><label>Confirmar contraseña</label><input name="password_confirm" type="password" minlength="8" required></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>"><?=h($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número de documento</label><input name="num_doc" maxlength="20" required></div><div class="field"><label>Primer nombre</label><input name="nombre1" required></div><div class="field"><label>Segundo nombre</label><input name="nombre2"></div><div class="field"><label>Primer apellido</label><input name="apellido1" required></div><div class="field"><label>Segundo apellido</label><input name="apellido2"></div><div class="field"><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" max="<?=date('Y-m-d')?>" required></div><div class="field"><label>Ingreso mensual</label><input type="number" name="ingreso_mensual" min="0" step="1000" required></div><div class="field"><label>Teléfono</label><input name="telefono"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field full"><label>Dirección</label><input name="direccion"></div></div><button class="button primary">Crear solicitud</button><p class="form-footnote">La afiliación quedará Pendiente mientras la EPS verifica el pago por fuera del portal.</p></form><footer>Aplicación académica · Uso local</footer></section></main>
    <script>document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===button.dataset.tab));}));<?php if($action==='register'):?>document.querySelector('[data-tab="register"]').click();<?php endif?></script></body></html><?php exit;
}

$role = (string)$_SESSION['role'];
$name = (string)$_SESSION['name'];
$dashboardError = '';
try {
    if ($role === 'paciente') {
        $query = $pdo->prepare('SELECT id_paciente,estado_afiliacion FROM pacientes WHERE id_usuario=?');
        $query->execute([$_SESSION['user_id']]); $patient = $query->fetch();
        $query = $pdo->query("SELECT h.id_horario,h.fecha,h.hora_inicio,m.nombre1||' '||m.apellido1 AS medico,e.nombre AS especialidad FROM horario h JOIN estado st USING(id_estado) JOIN medicos m USING(id_medico) JOIN especialidad e USING(id_especialidad) WHERE st.ambito='horario' AND st.nombre='Disponible' AND (h.fecha+h.hora_inicio)>CURRENT_TIMESTAMP ORDER BY h.fecha,h.hora_inicio"); $slots = $query->fetchAll();
        $services = $pdo->query('SELECT id_servicio,nombre,costo_base FROM servicio ORDER BY nombre')->fetchAll();
        $query = $pdo->prepare("SELECT c.id_cita,c.valor_cobrado,e.nombre AS estado,h.fecha,h.hora_inicio,s.nombre AS servicio,m.nombre1||' '||m.apellido1 AS medico,r.resultado FROM cita c JOIN estado e USING(id_estado) JOIN horario h USING(id_horario) JOIN servicio s USING(id_servicio) JOIN medicos m USING(id_medico) LEFT JOIN resultado_consulta r USING(id_cita) WHERE c.id_paciente=? ORDER BY h.fecha DESC,h.hora_inicio DESC");
        $query->execute([$patient['id_paciente']]); $appointments = $query->fetchAll();
        $query = $pdo->prepare('SELECT alergias,grupo_sanguineo,antecedentes FROM historial WHERE id_paciente=?'); $query->execute([$patient['id_paciente']]); $history = $query->fetch();
    } elseif ($role === 'medico') {
        $query = $pdo->prepare("SELECT c.id_cita,e.nombre AS estado,h.fecha,h.hora_inicio,p.nombre1||' '||p.apellido1 AS paciente,p.num_doc,p.telefono,s.nombre AS servicio,r.resultado,hist.alergias,hist.antecedentes,hist.grupo_sanguineo FROM medicos m JOIN horario h USING(id_medico) JOIN cita c USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado JOIN pacientes p USING(id_paciente) JOIN servicio s USING(id_servicio) JOIN historial hist USING(id_paciente) LEFT JOIN resultado_consulta r USING(id_cita) WHERE m.id_usuario=? ORDER BY h.fecha,h.hora_inicio");
        $query->execute([$_SESSION['user_id']]); $agenda = $query->fetchAll();
    } else {
        $patients = $pdo->query('SELECT p.id_paciente,p.nombre1,p.apellido1,p.num_doc,p.ingreso_mensual,p.estado_afiliacion,t.sigla FROM pacientes p JOIN tipo_documento t USING(id_tipo_doc) ORDER BY p.apellido1,p.nombre1')->fetchAll();
        $tariffs = $pdo->query('SELECT id_tarifa,nombre FROM tarifa ORDER BY id_tarifa')->fetchAll();
        $types = $pdo->query('SELECT id_tipo_doc,sigla FROM tipo_documento ORDER BY sigla')->fetchAll();
        $specialties = $pdo->query('SELECT id_especialidad,nombre FROM especialidad ORDER BY nombre')->fetchAll();
        $doctors = $pdo->query('SELECT id_medico,nombre1,apellido1 FROM medicos ORDER BY apellido1')->fetchAll();
        $clinics = $pdo->query('SELECT id_consultorio,nombre FROM consultorio ORDER BY nombre')->fetchAll();
    }
} catch (Throwable $exception) {
    $dashboardError = 'No se pudieron cargar los datos del panel: ' . $exception->getMessage();
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Panel</title><link rel="stylesheet" href="/assets/app.css"></head><body class="app-page"><header class="app-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><div class="user-menu"><?=h($name)?> · <?=h(ucfirst($role))?> <a class="button secondary compact" href="/?salir=1">Salir</a></div></header><main class="container"><p class="eyebrow"><?=h(strtoupper($role))?></p><h1><?= $role==='paciente'?'Mi portal':($role==='medico'?'Mi agenda':'Administración') ?></h1><?php if($message):?><div class="notice"><?=h($message)?></div><?php endif?><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($dashboardError):?><div class="notice error"><?=h($dashboardError)?></div><?php endif?>
<?php if($role==='paciente'):?><section class="panel"><h2>Estado de afiliación: <?=h($patient['estado_afiliacion']??'Sin perfil')?></h2><p>Las reservas están habilitadas cuando la afiliación está activa.</p></section><div class="two-column"><section class="panel"><h2>Agendar una cita</h2><?php if(($patient['estado_afiliacion']??'')!=='Activo'):?><p class="notice warning">Tu afiliación está pendiente de revisión por la EPS.</p><?php elseif(!$slots):?><p>No hay horarios disponibles en este momento.</p><?php else:?><form method="post"><?=csrfField()?><input type="hidden" name="accion" value="reservar_cita"><label>Servicio</label><select name="id_servicio" required><?php foreach($services as $s):?><option value="<?=h($s['id_servicio'])?>"><?=h($s['nombre'])?> · $<?=number_format((float)$s['costo_base'],0,',','.')?></option><?php endforeach?></select><label>Horario</label><select name="id_horario" required><?php foreach($slots as $s):?><option value="<?=h($s['id_horario'])?>"><?=h($s['fecha'].' '.substr((string)$s['hora_inicio'],0,5).' · '.$s['medico'].' · '.$s['especialidad'])?></option><?php endforeach?></select><button class="button primary">Reservar cita</button></form><?php endif?></section><section class="panel"><h2>Mi historia clínica</h2><p><strong>Alergias:</strong> <?=h($history['alergias']??'Sin registro')?></p><p><strong>Grupo sanguíneo:</strong> <?=h($history['grupo_sanguineo']??'Sin registro')?></p><p><strong>Antecedentes:</strong> <?=h($history['antecedentes']??'Sin registro')?></p></section></div><section class="panel"><h2>Mis citas</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Servicio</th><th>Valor</th><th>Estado</th><th>Resultado</th></tr></thead><tbody><?php foreach($appointments as $a):?><tr><td><?=h($a['fecha'])?></td><td><?=h(substr((string)$a['hora_inicio'],0,5))?></td><td><?=h($a['medico'])?></td><td><?=h($a['servicio'])?></td><td>$<?=number_format((float)$a['valor_cobrado'],0,',','.')?></td><td><?=h($a['estado'])?></td><td><?=h($a['resultado']??'Pendiente')?></td></tr><?php endforeach?></tbody></table></div></section>
<?php elseif($role==='medico'):?><section class="panel"><h2>Pacientes de mi agenda</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Documento</th><th>Servicio</th><th>Historia resumida</th><th>Resultado</th></tr></thead><tbody><?php foreach($agenda as $a):?><tr><td><?=h($a['fecha'])?></td><td><?=h(substr((string)$a['hora_inicio'],0,5))?></td><td><?=h($a['paciente'])?></td><td><?=h($a['num_doc'])?></td><td><?=h($a['servicio'])?></td><td>Alergias: <?=h($a['alergias']?:'—')?>; antecedentes: <?=h($a['antecedentes']?:'—')?>; grupo: <?=h($a['grupo_sanguineo']?:'—')?></td><td><form method="post"><?=csrfField()?><input type="hidden" name="accion" value="guardar_resultado"><input type="hidden" name="id_cita" value="<?=h($a['id_cita'])?>"><textarea name="resultado" minlength="3" required><?=h($a['resultado']??'')?></textarea><button class="button primary compact">Guardar</button></form></td></tr><?php endforeach?></tbody></table></div></section>
<?php else: ?><section class="panel"><h2>Afiliaciones</h2><p>La página no procesa pagos; actualiza el estado al confirmar el pago externo.</p><div class="table-wrap"><table><thead><tr><th>Paciente</th><th>Documento</th><th>Ingreso</th><th>Estado</th><th>Actualizar</th></tr></thead><tbody><?php foreach($patients as $p):?><tr><td><?=h($p['nombre1'].' '.$p['apellido1'])?></td><td><?=h($p['sigla'].' '.$p['num_doc'])?></td><td>$<?=number_format((float)$p['ingreso_mensual'],0,',','.')?></td><td><?=h($p['estado_afiliacion'])?></td><td><form method="post" class="inline-form"><?=csrfField()?><input type="hidden" name="accion" value="aprobar_afiliacion"><input type="hidden" name="id_paciente" value="<?=h($p['id_paciente'])?>"><select name="estado_afiliacion"><option>Pendiente</option><option>Activo</option><option>Inactivo</option></select><select name="id_tarifa"><option value="">Tarifa</option><?php foreach($tariffs as $t):?><option value="<?=h($t['id_tarifa'])?>"><?=h($t['nombre'])?></option><?php endforeach?></select><button class="button primary compact">Guardar</button></form></td></tr><?php endforeach?></tbody></table></div></section><div class="two-column"><section class="panel"><h2>Crear cuenta médica</h2><form method="post" class="form-grid"><?=csrfField()?><input type="hidden" name="accion" value="crear_medico"><div class="field"><label>Usuario</label><input name="username" required></div><div class="field"><label>Contraseña temporal</label><input name="password" type="password" minlength="8" required></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc"><?php foreach($types as $t):?><option value="<?=h($t['id_tipo_doc'])?>"><?=h($t['sigla'])?></option><?php endforeach?></select></div><div class="field"><label>Número</label><input name="num_doc" required></div><div class="field"><label>Nombre</label><input name="nombre1" required></div><div class="field"><label>Apellido</label><input name="apellido1" required></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field"><label>Teléfono</label><input name="telefono"></div><div class="field"><label>Especialidad</label><select name="id_especialidad"><?php foreach($specialties as $s):?><option value="<?=h($s['id_especialidad'])?>"><?=h($s['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Consultorio</label><select name="id_consultorio"><option value="">Sin asignar</option><?php foreach($clinics as $c):?><option value="<?=h($c['id_consultorio'])?>"><?=h($c['nombre'])?></option><?php endforeach?></select></div><div class="field full"><button class="button primary">Crear médico</button></div></form></section><section class="panel"><h2>Configurar horario</h2><form method="post"><?=csrfField()?><input type="hidden" name="accion" value="crear_horario"><label>Médico</label><select name="id_medico"><?php foreach($doctors as $d):?><option value="<?=h($d['id_medico'])?>"><?=h($d['nombre1'].' '.$d['apellido1'])?></option><?php endforeach?></select><label>Fecha</label><input name="fecha" type="date" min="<?=date('Y-m-d')?>" required><div class="form-grid"><div class="field"><label>Inicio</label><input name="hora_inicio" type="time" required></div><div class="field"><label>Fin</label><input name="hora_fin" type="time" required></div></div><button class="button primary">Agregar horario</button></form></section></div><?php endif?></main></body></html>
