<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax']);
session_start();

// Escapa el texto antes de mostrarlo en la página.
function escaparHtml(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Agrega el token de seguridad al formulario.
function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . escaparHtml($_SESSION['csrf']) . '">';
}

// Comprueba si el perfil puede hacer esta acción.
function tienePermiso(PDO $pdo, string $role, string $permission): bool
{
    $permissions = [
        'paciente' => ['ver_datos_propios', 'reservar_cita'],
        'medico' => ['ver_agenda', 'registrar_resultado'],
        'administrador' => ['gestionar_afiliado', 'gestionar_medico', 'gestionar_horarios'],
    ];
    return in_array($permission, $permissions[$role] ?? [], true);
}

// Detiene la acción si el perfil no tiene permiso.
function exigirPermiso(PDO $pdo, string $role, string $permission): void
{
    if (!tienePermiso($pdo, $role, $permission)) {
        http_response_code(403);
        throw new RuntimeException('Tu rol no tiene permiso para realizar esta acción.');
    }
}

// Busca la categoría que corresponde al ingreso.
function buscarTarifaPorIngreso(PDO $pdo, float $income): ?int
{
    $query = $pdo->prepare('SELECT id_tarifa FROM tarifa WHERE ingreso_min <= ? AND ingreso_max >= ? ORDER BY ingreso_min DESC LIMIT 1');
    $query->execute([$income, $income]);
    $id = $query->fetchColumn();
    return $id === false ? null : (int)$id;
}

// Valida la fecha y la edad del afiliado.
function validarFechaNacimientoAfiliado(mixed $raw, string $documentType): string
{
    $value = trim((string)$raw);
    $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $today = new DateTimeImmutable('today');
    if (!$birthDate || $birthDate->format('Y-m-d') !== $value || $birthDate >= $today) {
        throw new RuntimeException('La fecha de nacimiento debe ser anterior a hoy.');
    }
    if ($documentType === 'CC' && $birthDate >= $today->modify('-18 years')) {
        throw new RuntimeException('Para registrarse con cédula (CC), el afiliado debe ser mayor de 18 años.');
    }
    return $birthDate->format('Y-m-d');
}

// Valida y normaliza la opción de sexo.
function validarSexo(mixed $raw): string
{
    $sex = trim((string)$raw);
    $values = [
        'F'=>'Femenino', 'Femenino'=>'Femenino', 'Femenino (F)'=>'Femenino',
        'M'=>'Masculino', 'Masculino'=>'Masculino', 'Masculino (M)'=>'Masculino',
        'X'=>'No binario', 'No binario'=>'No binario', 'No binario (X)'=>'No binario',
        'Intersexual'=>'Intersexual', 'Intersexual (X)'=>'Intersexual',
        'Otro'=>'Otro', 'Otro marcador (X)'=>'Otro', 'No especificado'=>'Otro',
        'Prefiero no informar'=>'Otro',
    ];
    if (!isset($values[$sex])) throw new RuntimeException('Selecciona una opción válida para sexo.');
    return $values[$sex];
}

// Prepara el sexo guardado para mostrarlo.
function mostrarSexoGuardado(mixed $raw): string
{
    return match ((string)$raw) {
        'F', 'Femenino', 'Femenino (F)' => 'Femenino',
        'M', 'Masculino', 'Masculino (M)' => 'Masculino',
        'Intersexual', 'Intersexual (X)' => 'Intersexual',
        'Otro', 'Otro marcador (X)', 'No especificado', 'Prefiero no informar' => 'Otro',
        default => 'No binario',
    };
}

// Valida el número según el tipo de documento.
function validarNumeroDocumento(mixed $raw, string $documentType): string
{
    $document = strtoupper(trim((string)$raw));
    $numericTypes = ['CN', 'RC', 'TI', 'CC'];
    $pattern = in_array($documentType, $numericTypes, true) ? '/^[0-9]{1,20}$/' : '/^[A-Z0-9]{1,20}$/';
    if (!preg_match($pattern, $document)) {
        throw new RuntimeException(in_array($documentType, $numericTypes, true)
            ? 'El documento seleccionado debe contener solo números.'
            : 'El documento debe contener de 1 a 20 letras o números, sin espacios ni puntos.');
    }
    return $document;
}

// Convierte la hora AM/PM a formato de 24 horas.
function convertirHoraAFormato24(mixed $hourRaw, mixed $minuteRaw, mixed $periodRaw): string
{
    $hourText = trim((string)$hourRaw);
    $minuteText = trim((string)$minuteRaw);
    $period = strtoupper(trim((string)$periodRaw));
    if (!preg_match('/^(?:[1-9]|1[0-2])$/', $hourText)
        || !preg_match('/^(?:[0-9]|0[0-9]|[1-5][0-9])$/', $minuteText)
        || !in_array($period, ['AM', 'PM'], true)) {
        throw new RuntimeException('Selecciona una hora, minutos y AM/PM válidos.');
    }
    $hour = (int)$hourText % 12 + ($period === 'PM' ? 12 : 0);
    return sprintf('%02d:%02d', $hour, (int)$minuteText);
}

// Muestra la hora en formato de 12 horas.
function mostrarHoraFormato12(mixed $raw): string
{
    $raw = substr((string)$raw, 0, 5);
    $time = DateTimeImmutable::createFromFormat('!H:i', $raw);
    return $time ? $time->format('g:i A') : $raw;
}

// Valida el teléfono y permite dejarlo vacío.
function validarTelefono(mixed $raw): ?string
{
    $phone = trim((string)$raw);
    if ($phone !== '' && !preg_match('/^[0-9]{1,10}$/', $phone)) {
        throw new RuntimeException('El teléfono debe tener solo números y máximo 10 dígitos.');
    }
    return $phone === '' ? null : $phone;
}

// Regresa a la página principal.
function volverAlInicio(): never
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
    volverAlInicio();
}

$pdo = null;
$error = '';
$message = '';
$agenda = [];
$completedAppointments = [];
$patients = [];
$patientSearchError = '';
$types = [];
$specialties = [];
$doctors = [];
$clinics = [];
$action = (string)($_POST['accion'] ?? '');

try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT limpiar_horarios_vencidos()')->closeCursor();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            throw new RuntimeException('El formulario venció. Recarga la página e inténtalo otra vez.');
        }

        if (empty($_SESSION['user_id'])) {
            if ($action === 'admin_login') {
                $adminCode = trim((string)($_POST['admin_code'] ?? ''));
                if (!preg_match('/^[0-9]{8}$/', $adminCode)) throw new RuntimeException('El código de administrador debe tener 8 números.');
                $query = $pdo->prepare("SELECT id_usuario,password_hash,activo FROM usuario WHERE username='99999999' AND username=?");
                $query->execute([$adminCode]);
                $admin = $query->fetch(PDO::FETCH_ASSOC);
                if (!$admin || !$admin['activo'] || !password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
                    throw new RuntimeException('Código o contraseña de administrador incorrectos.');
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$admin['id_usuario'];
                $_SESSION['role'] = 'administrador';
                $_SESSION['name'] = 'Administrador';
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                volverAlInicio();
            }
            if ($action === 'login') {
                $documentTypeSigla = strtoupper(trim((string)($_POST['id_tipo_doc'] ?? '')));
                $typeLookup = $pdo->prepare('SELECT id_tipo_doc FROM tipo_documento WHERE sigla = ?');
                $typeLookup->execute([$documentTypeSigla]);
                $documentTypeValue = $typeLookup->fetchColumn();
                if ($documentTypeValue === false) throw new RuntimeException('Selecciona un tipo de documento válido.');
                $documentType = (int)$documentTypeValue;
                $document = validarNumeroDocumento($_POST['num_doc'] ?? '', $documentTypeSigla);
                $query = $pdo->prepare(
                    "SELECT u.id_usuario, u.password_hash, u.activo,
"
                    . "CASE WHEN p.id_afiliado IS NOT NULL THEN 'paciente' WHEN m.id_medico IS NOT NULL THEN 'medico' ELSE '' END AS rol,
"
                    . "COALESCE(p.nombre1 || ' ' || p.apellido1, m.nombre1 || ' ' || m.apellido1, 'Administrador') AS nombre
"
                    . 'FROM usuario u LEFT JOIN afiliado p USING (id_usuario) LEFT JOIN medico m USING (id_usuario) '
                    . 'WHERE (p.id_tipo_doc = ? AND p.num_doc = ?) '
                    . 'OR (m.id_tipo_doc = ? AND m.num_documento = ?) '
                );
                $query->execute([$documentType, $document, $documentType, $document]);
                $matches = $query->fetchAll(PDO::FETCH_ASSOC);
                $user = count($matches) === 1 ? $matches[0] : false;
                if (!$user || !$user['activo'] || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                    throw new RuntimeException('Tipo o número de documento, o contraseña, incorrectos.');
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id_usuario'];
                $_SESSION['role'] = (string)$user['rol'];
                $_SESSION['name'] = (string)$user['nombre'];
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                volverAlInicio();
            }

            if ($action === 'register') {
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['password_confirm'] ?? '');
                $documentType = (int)($_POST['id_tipo_doc'] ?? 0);
                $phone = validarTelefono($_POST['telefono'] ?? '');
                $sex = validarSexo($_POST['sexo'] ?? '');
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
                if ($type === '') throw new RuntimeException('Selecciona un tipo de documento válido.');
                $document = validarNumeroDocumento($_POST['num_doc'] ?? '', $type);
                $birth = validarFechaNacimientoAfiliado($_POST['fecha_nacimiento'] ?? '', $type);
                $tariffId = buscarTarifaPorIngreso($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso declarado no coincide con una categoría tarifaria.');
                $allergies = trim((string)($_POST['alergias'] ?? ''));
                $bloodType = trim((string)($_POST['grupo_sanguineo'] ?? ''));
                $historyNotes = trim((string)($_POST['antecedentes'] ?? ''));
                if ($allergies === '' || $historyNotes === '') throw new RuntimeException('Completa alergias y antecedentes. Si no tienes, escribe “No conocidas” o “Ninguno”.');
                if (strlen($allergies) > 4000 || strlen($historyNotes) > 8000) throw new RuntimeException('La información médica supera el límite permitido.');
                if ($bloodType !== '' && !in_array($bloodType, ['A+','A-','B+','B-','AB+','AB-','O+','O-','No sé'], true)) throw new RuntimeException('Selecciona un grupo sanguíneo válido.');

                $pdo->beginTransaction();
                $userQuery = $pdo->prepare('INSERT INTO usuario (username, password_hash) VALUES (?, ?) RETURNING id_usuario');
                $userQuery->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$userQuery->fetchColumn();
                $patientQuery = $pdo->prepare(
                    'INSERT INTO afiliado (id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id_afiliado'
                );
                $patientQuery->execute([
                    $userId, $documentType, $document,
                    trim((string)$_POST['nombre1']), trim((string)($_POST['nombre2'] ?? '')) ?: null,
                    trim((string)$_POST['apellido1']), trim((string)($_POST['apellido2'] ?? '')) ?: null,
                    $sex, $birth,
                    $phone,
                    trim((string)($_POST['correo'] ?? '')) ?: null,
                    trim((string)($_POST['direccion'] ?? '')) ?: null, $income,
                    $tariffId,
                ]);
                $patientId = (int)$patientQuery->fetchColumn();
                $history = $pdo->prepare("INSERT INTO historial (id_afiliado,alergias,grupo_sanguineo,antecedentes) VALUES (?,?,NULLIF(?,''),NULLIF(?,''))");
                $history->execute([$patientId,$allergies ?: null,$bloodType,$historyNotes ?: null]);
                $pdo->commit();
                $message = 'Cuenta creada. Tu afiliación está Pendiente hasta que la EPS verifique el pago externo.';
            }
        } else {
            $role = (string)$_SESSION['role'];
            if ($action === 'reservar_cita' && $role === 'paciente') {
                exigirPermiso($pdo, $role, 'reservar_cita');
                $query = $pdo->prepare("SELECT id_afiliado FROM afiliado WHERE id_usuario=? AND estado_afiliacion='Activo'");
                $query->execute([$_SESSION['user_id']]);
                $patientId = $query->fetchColumn();
                if (!$patientId) throw new RuntimeException('La afiliación debe estar Activa para reservar.');
                $query = $pdo->prepare("SELECT COUNT(*) FROM horario escaparHtml JOIN estado e ON e.id_estado=escaparHtml.id_estado AND e.ambito='horario' JOIN medico m ON m.id_medico=escaparHtml.id_medico JOIN servicio_especialidad se ON se.id_servicio=? AND se.id_especialidad=m.id_especialidad WHERE escaparHtml.id_horario=? AND e.nombre='Disponible' AND escaparHtml.fecha>CURRENT_DATE AND (escaparHtml.fecha+escaparHtml.hora_inicio)>CURRENT_TIMESTAMP");
                $query->execute([(int)($_POST['id_servicio'] ?? 0), (int)($_POST['id_horario'] ?? 0)]);
                if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Ese horario no está disponible para el servicio elegido. Selecciona un médico de la especialidad correspondiente y vuelve a intentarlo.');
                $query = $pdo->prepare("INSERT INTO cita (id_afiliado,id_servicio,id_horario,id_estado) SELECT ?,?, ?,id_estado FROM estado WHERE ambito='cita' AND nombre='Programada'");
                $query->execute([$patientId,(int)($_POST['id_servicio'] ?? 0),(int)($_POST['id_horario'] ?? 0)]);
                $message = 'Cita reservada correctamente.';

            } elseif ($action === 'cancelar_cita' && $role === 'paciente') {
                exigirPermiso($pdo, $role, 'reservar_cita');
                $query = $pdo->prepare("DELETE FROM cita c USING afiliado p, horario escaparHtml WHERE c.id_afiliado=p.id_afiliado AND c.id_horario=escaparHtml.id_horario AND p.id_usuario=? AND c.id_cita=? AND escaparHtml.fecha+escaparHtml.hora_inicio>CURRENT_TIMESTAMP AND c.id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Programada')");
                $query->execute([$_SESSION['user_id'], (int)($_POST['id_cita'] ?? 0)]);
                if ($query->rowCount() !== 1) throw new RuntimeException('La cita ya no se puede cancelar o no pertenece a tu cuenta.');
                $message = 'Cita cancelada. El horario quedó disponible.';
            } elseif ($action === 'guardar_resultado' && $role === 'medico') {
                exigirPermiso($pdo, $role, 'registrar_resultado');
                $appointmentId = (int)($_POST['id_cita'] ?? 0);
                $query = $pdo->prepare("SELECT m.id_medico, c.id_afiliado FROM medico m JOIN cita c ON c.id_cita=? JOIN horario escaparHtml USING(id_horario) JOIN estado ec ON ec.id_estado=c.id_estado AND ec.ambito='cita' AND ec.nombre='Programada' WHERE m.id_usuario=? AND m.id_medico=escaparHtml.id_medico");
                $query->execute([$appointmentId,$_SESSION['user_id']]);
                $appointment = $query->fetch(PDO::FETCH_ASSOC);
                $result = trim((string)($_POST['resultado'] ?? ''));
                if (!$appointment) throw new RuntimeException('La cita ya no está pendiente o no pertenece a tu agenda.');
                if (strlen($result) < 3) throw new RuntimeException('Escribe un resultado de al menos 3 caracteres.');
                $pdo->beginTransaction();
                $query = $pdo->prepare("INSERT INTO historial (id_afiliado,observaciones) VALUES (?,?) ON CONFLICT (id_afiliado) DO UPDATE SET observaciones=concat_ws(E'\\n',NULLIF(historial.observaciones,''),EXCLUDED.observaciones)");
                $query->execute([(int)$appointment['id_afiliado'], "Cita #{$appointmentId}: {$result}"]);
                $query = $pdo->prepare("UPDATE cita SET id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Atendida') WHERE id_cita=?");
                $query->execute([$appointmentId]);
                if ($query->rowCount() !== 1) throw new RuntimeException('No se pudo cambiar la cita a Atendida.');
                $pdo->commit();
                $message = 'Resultado de atención guardado.';
            } elseif ($action === 'aprobar_afiliacion' && $role === 'administrador') {
                exigirPermiso($pdo, $role, 'gestionar_afiliado');
                $state = (string)($_POST['estado_afiliacion'] ?? '');
                if (!in_array($state, ['Pendiente','Activo','Inactivo'], true)) throw new RuntimeException('Selecciona un estado de afiliación válido.');
                $query = $pdo->prepare('UPDATE afiliado SET estado_afiliacion=? WHERE id_afiliado=?');
                $query->execute([$state,(int)($_POST['id_paciente'] ?? 0)]);
                $message = 'Afiliación actualizada.';
            } elseif ($action === 'crear_paciente_admin' && $role === 'administrador') {
                exigirPermiso($pdo, $role, 'gestionar_afiliado');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $phone = validarTelefono($_POST['telefono'] ?? '');
                $sex = validarSexo($_POST['sexo'] ?? '');
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
                $typeId = (int)($_POST['id_tipo_doc'] ?? 0);
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?');
                $typeQuery->execute([$typeId]);
                $docType = (string)$typeQuery->fetchColumn();
                $birth = validarFechaNacimientoAfiliado($birth, $docType);
                if ($docType === '') throw new RuntimeException('Selecciona un tipo de documento válido.');
                $doc = validarNumeroDocumento($_POST['num_doc'] ?? '', $docType);
                $tariffId = buscarTarifaPorIngreso($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso no coincide con una categoría tarifaria.');
                $allergies = trim((string)($_POST['alergias'] ?? ''));
                $bloodType = trim((string)($_POST['grupo_sanguineo'] ?? ''));
                $historyNotes = trim((string)($_POST['antecedentes'] ?? ''));
                if ($allergies === '' || $historyNotes === '') throw new RuntimeException('Completa alergias y antecedentes. Si no tiene, escribe “No conocidas” o “Ninguno”.');
                if (strlen($allergies) > 4000 || strlen($historyNotes) > 8000) throw new RuntimeException('La información médica supera el límite permitido.');
                if ($bloodType !== '' && !in_array($bloodType, ['A+','A-','B+','B-','AB+','AB-','O+','O-','No sé'], true)) throw new RuntimeException('Selecciona un grupo sanguíneo válido.');
                $pdo->beginTransaction();
                $query = $pdo->prepare('INSERT INTO usuario(username,password_hash) VALUES(?,?) RETURNING id_usuario');
                $query->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
                $userId = (int)$query->fetchColumn();
                $query = $pdo->prepare('INSERT INTO afiliado(id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual,id_tarifa,estado_afiliacion) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Pendiente\') RETURNING id_afiliado');
                $query->execute([$userId,$typeId,$doc,trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,$sex,$birth,$phone,trim((string)($_POST['correo']??''))?:null,trim((string)($_POST['direccion']??''))?:null,$income,$tariffId]);
                $patientId = (int)$query->fetchColumn();
                $history = $pdo->prepare("INSERT INTO historial (id_afiliado,alergias,grupo_sanguineo,antecedentes) VALUES (?,?,NULLIF(?,''),NULLIF(?,''))");
                $history->execute([$patientId,$allergies,$bloodType,$historyNotes]);
                $pdo->commit();
                $message = 'Afiliado creado con afiliación Pendiente. La categoría se asignó según el ingreso.';
            } elseif ($action === 'actualizar_paciente' && $role === 'administrador') {
                exigirPermiso($pdo, $role, 'gestionar_afiliado');
                $patientId = (int)($_POST['id_paciente'] ?? 0);
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $phone = validarTelefono($_POST['telefono'] ?? '');
                $sex = validarSexo($_POST['sexo'] ?? '');
                $income = filter_var($_POST['ingreso_mensual'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/', $username) || $income === false || $income < 0) throw new RuntimeException('Revisa el usuario y el ingreso mensual.');
                foreach (['nombre1','nombre2','apellido1','apellido2'] as $field) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) throw new RuntimeException('Los nombres y apellidos solo pueden contener letras.');
                }
                $birth = (string)($_POST['fecha_nacimiento'] ?? '');
                $typeId = (int)($_POST['id_tipo_doc'] ?? 0);
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?'); $typeQuery->execute([$typeId]);
                $docType = (string)$typeQuery->fetchColumn(); $birth = validarFechaNacimientoAfiliado($birth, $docType);
                if ($docType === '') throw new RuntimeException('Selecciona un tipo de documento válido.');
                $doc = validarNumeroDocumento($_POST['num_doc'] ?? '', $docType);
                $tariffId = buscarTarifaPorIngreso($pdo, (float)$income);
                if (!$tariffId) throw new RuntimeException('El ingreso no coincide con una categoría tarifaria.');
                $pdo->beginTransaction();
                $userIdQuery = $pdo->prepare('SELECT id_usuario FROM afiliado WHERE id_afiliado=?'); $userIdQuery->execute([$patientId]);
                $userId = $userIdQuery->fetchColumn();
                if (!$userId) throw new RuntimeException('No se encontró el afiliado.');
                $pdo->prepare('UPDATE usuario SET username=? WHERE id_usuario=?')->execute([$username,$userId]);
                $query = $pdo->prepare('UPDATE afiliado SET id_tipo_doc=?,num_doc=?,nombre1=?,nombre2=?,apellido1=?,apellido2=?,sexo=?,fecha_nacimiento=?,telefono=?,correo=?,direccion=?,ingreso_mensual=?,id_tarifa=? WHERE id_afiliado=?');
                $query->execute([$typeId,$doc,trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,$sex,$birth,$phone,trim((string)($_POST['correo']??''))?:null,trim((string)($_POST['direccion']??''))?:null,$income,$tariffId,$patientId]);
                $pdo->commit();
                $message = 'Datos del afiliado actualizados. La categoría se recalculó según el ingreso.';
            } elseif ($action === 'crear_medico' && $role === 'administrador') {
                exigirPermiso($pdo, $role, 'gestionar_medico');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $phone = validarTelefono($_POST['telefono'] ?? '');
                if (!preg_match('/^[a-z][a-z._-]{3,49}$/',$username) || strlen($password)<8) throw new RuntimeException('Usuario o contraseña inválidos.');
                foreach (['nombre1','nombre2','apellido1','apellido2'] as $field) {
                    $value = trim((string)($_POST[$field]??''));
                    if ($value !== '' && !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $value)) throw new RuntimeException('El nombre y el apellido solo pueden contener letras.');
                }
                $typeQuery = $pdo->prepare('SELECT sigla FROM tipo_documento WHERE id_tipo_doc=?');
                $typeQuery->execute([(int)($_POST['id_tipo_doc'] ?? 0)]);
                $docType = (string)$typeQuery->fetchColumn();
                if ($docType === '') throw new RuntimeException('Selecciona un tipo de documento válido.');
                $doc = validarNumeroDocumento($_POST['num_doc'] ?? '', $docType);
                $pdo->beginTransaction();
                $query = $pdo->prepare('INSERT INTO usuario (username,password_hash) VALUES (?,?) RETURNING id_usuario');
                $query->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
                $userId = (int)$query->fetchColumn();
                $query = $pdo->prepare('INSERT INTO medico (id_usuario,id_tipo_doc,num_documento,nombre1,nombre2,apellido1,apellido2,telefono,correo,id_especialidad,id_consultorio) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $query->execute([$userId,(int)$_POST['id_tipo_doc'],trim((string)$_POST['num_doc']),trim((string)$_POST['nombre1']),trim((string)($_POST['nombre2']??''))?:null,trim((string)$_POST['apellido1']),trim((string)($_POST['apellido2']??''))?:null,$phone,trim((string)($_POST['correo']??''))?:null,(int)$_POST['id_especialidad'],($_POST['id_consultorio']??'')===''?null:(int)$_POST['id_consultorio']]);
                $pdo->commit();
                $message = 'Perfil médico creado.';
            } elseif ($action === 'crear_horario' && $role === 'administrador') {
                exigirPermiso($pdo, $role, 'gestionar_horarios');
                $startTime = trim((string)($_POST['hora_inicio'] ?? ''));
                $endTime = trim((string)($_POST['hora_fin'] ?? ''));
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime) || $startTime < '06:00' || $endTime > '20:00' || $endTime <= $startTime) {
                    throw new RuntimeException('Los horarios deben estar entre las 6:00 a. m. y las 8:00 p. m., y la hora final debe ser posterior a la inicial.');
                }
                [$startHour,$startMinute]=array_map('intval',explode(':',$startTime));
                [$endHour,$endMinute]=array_map('intval',explode(':',$endTime));
                if (($endHour*60+$endMinute)-($startHour*60+$startMinute)>60) {
                    throw new RuntimeException('Cada cita debe durar máximo una hora.');
                }
                $doctorCheck = $pdo->prepare('SELECT 1 FROM medico WHERE id_medico=? AND id_especialidad=?');
                $doctorCheck->execute([(int)($_POST['id_medico'] ?? 0),(int)($_POST['id_especialidad'] ?? 0)]);
                if (!$doctorCheck->fetchColumn()) throw new RuntimeException('Elige un médico que corresponda a la especialidad.');
                $query = $pdo->prepare("INSERT INTO horario (id_medico,fecha,hora_inicio,hora_fin,id_estado) SELECT ?,?,?,?,id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible'");
                $query->execute([(int)$_POST['id_medico'],(string)$_POST['fecha'],$startTime,$endTime]);
                $message = 'Horario agregado.';
            } else {
                throw new RuntimeException('Esa acción no está disponible para tu perfil.');
            }
        }
    }
} catch (Throwable $exception) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof PDOException) {
        $sqlState = (string)($exception->errorInfo[0] ?? $exception->getCode());
        // Ocultar únicamente el aviso genérico tras un intento de acceso.
        // En los paneles se conserva el mensaje para que el administrador pueda actuar.
        if (empty($_SESSION['user_id']) && $action === 'login') {
            $error = '';
        } elseif ($action === 'reservar_cita' && $sqlState === 'P0001') {
            $error = 'Ya tienes una cita pendiente que coincide con ese horario. Elige otro horario.';
        } elseif ($action === 'guardar_resultado') {
            $error = 'No se pudo guardar el resultado: ' . $exception->getMessage();
        } elseif ($sqlState === '42P01') {
            $error = 'No se pudo consultar la base de datos. Verifica que PostgreSQL esté activo y que proyecto.sql se haya instalado.';
        } else {
            $error = 'No se pudo completar la operación. Verifica la conexión y que la base esté actualizada.';
        }
    } else {
        $error = $exception->getMessage();
    }
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
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Ingreso</title><link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="stylesheet" href="/assets/app.css"><style>
.panel-disclosure{padding:0;overflow:hidden}
.panel-disclosure>summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 24px;color:var(--blue-dark);cursor:pointer;font-size:1.08rem;font-weight:650;list-style:none}
.panel-disclosure>summary::-webkit-details-marker{display:none}
.panel-disclosure>summary::after{content:'+';display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:50%;background:#edf5f8;color:var(--blue-dark);font-size:1.25rem}
.panel-disclosure[open]>summary::after{content:'−'}
.panel-disclosure>summary:hover{background:#f7fafb}
.panel-disclosure-content{padding:0 24px 24px}
.panel-disclosure-content>.section-heading{margin-top:0}
@media(max-width:640px){.panel-disclosure>summary{padding:17px}.panel-disclosure-content{padding:0 17px 17px}}
.admin-login-panel{max-width:520px;margin:20px auto}
</style></head><body class="auth-page">
    <header class="site-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><a class="help-trigger" href="/index.php?admin=1">Acceso administrador</a></header>
    <main class="auth-layout"><section class="auth-illustration"><img src="/assets/ilustracion-familia.png" alt="Familia reunida"><div><p class="eyebrow">EPS Villavicencio</p><h1>Tu salud, más cerca.</h1><p>Consulta tus citas y encuentra los servicios disponibles.</p></div></section>
    <section class="auth-panel"><p class="eyebrow">Portal de afiliados</p><h2>Bienvenido</h2><p>Ingresa con tu tipo y número de documento o crea una cuenta para solicitar una afiliación.</p><?php if($error && $action !== 'admin_login'):?><div class="notice error"><?=escaparHtml($error)?></div><?php endif?><?php if($message):?><div class="notice"><?=escaparHtml($message)?></div><?php endif?>
<?php if(isset($_GET['admin']) || $action==='admin_login'):?>
    <section class="panel admin-login-panel"><p class="eyebrow">Acceso privado</p><h2>Administrador</h2><?php if($error):?><div class="notice error"><?=escaparHtml($error)?></div><?php endif?><form method="post" action="/index.php?admin=1" class="auth-form"><input type="hidden" name="accion" value="admin_login"><?=campoCsrf()?> <label>Código de administrador</label><input name="admin_code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="username"><label>Contraseña</label><input name="password" type="password" required autocomplete="current-password"><button class="button primary">Entrar como administrador</button></form><p><a href="/">Volver al ingreso</a></p></section>
<?php else:?>
    <div class="tabs"><button type="button" class="tab active" data-tab="login">Iniciar sesión</button><button type="button" class="tab" data-tab="register">Crear cuenta</button></div>
    <form method="post" class="auth-form tab-panel active" data-panel="login"><input type="hidden" name="accion" value="login"><?=campoCsrf()?><label>Tipo de documento *</label><select name="id_tipo_doc" id="login-document-type" required><option value="" selected disabled>Selecciona</option><?php foreach($types as $t):?><option value="<?=escaparHtml($t['sigla'])?>"><?=escaparHtml($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select><label>Número de documento *</label><input name="num_doc" id="login-document-number" required maxlength="20" autocomplete="username" placeholder="Escribe tu número de documento"><label>Contraseña *</label><input name="password" type="password" required autocomplete="current-password"><button class="button primary">Ingresar</button></form>
    <form method="post" class="auth-form tab-panel validate-live" data-panel="register" data-validate-form><input type="hidden" name="accion" value="register"><?=campoCsrf()?><div class="form-grid"><div class="field full"><label>Usuario (4 a 50 letras, sin números)</label><input name="username" pattern="[A-Za-z][A-Za-z._-]{3,49}" required data-rule="username"></div><div class="field"><label>Contraseña (mínimo 8 caracteres)</label><input name="password" type="password" minlength="8" required data-rule="password"></div><div class="field"><label>Confirmar contraseña</label><input name="password_confirm" type="password" minlength="8" required data-rule="password-confirm"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=escaparHtml($t['id_tipo_doc'])?>"><?=escaparHtml($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número de documento</label><input name="num_doc" maxlength="20" required data-document-number></div><div class="field"><label>Primer nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name"></div><div class="field"><label>Primer apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name"></div><div class="field"><label>Sexo en documento de identidad</label><select name="sexo" required><option value="" selected disabled>Selecciona</option><?php foreach(['Femenino'=>'Femenino','Masculino'=>'Masculino','No binario'=>'No binario','Intersexual'=>'Intersexual','Otro'=>'Otro'] as $sexCode=>$sexName):?><option value="<?=escaparHtml($sexCode)?>"><?=escaparHtml($sexName)?></option><?php endforeach?></select></div><div class="field"><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" max="<?=date('Y-m-d', strtotime('-1 day'))?>" required></div><div class="field"><label>Ingreso mensual</label><input type="number" name="ingreso_mensual" min="0" step="1000" required></div><div class="field"><label>Teléfono (máximo 10 dígitos)</label><input name="telefono" type="tel" inputmode="numeric" pattern="[0-9]{0,10}" maxlength="10"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field full"><label>Dirección</label><input name="direccion"></div><div class="field full"><h3>Información de salud</h3><small class="field-help">Completa estos datos; si no tienes alergias o antecedentes, indícalo. Después aparecerán en tu portal para consulta.</small></div><div class="field full"><label>Alergias</label><textarea name="alergias" maxlength="1000" placeholder="Si no tienes, escribe: No conocidas." required></textarea></div><div class="field"><label>Grupo sanguíneo</label><select name="grupo_sanguineo"><option value="">No lo sé / Sin registrar</option><?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-','No sé'] as $bloodType):?><option value="<?=escaparHtml($bloodType)?>"><?=escaparHtml($bloodType)?></option><?php endforeach?></select></div><div class="field full"><label>Antecedentes médicos</label><textarea name="antecedentes" maxlength="2000" placeholder="Si no tienes, escribe: Ninguno." required></textarea></div></div><button class="button primary">Crear solicitud</button><p class="form-footnote">La afiliación quedará Pendiente mientras la EPS verifica el pago por fuera del portal.</p></form><?php endif?>
    <footer>Aplicación académica · Uso local</footer></section></main>

    <script>
    const loginDocumentType=document.getElementById('login-document-type');
    const loginDocumentNumber=document.getElementById('login-document-number');
    // Ajusta la validación del documento en el acceso.
function configurarReglasDocumentoIngreso(){
      if(!loginDocumentType||!loginDocumentNumber)return;
      const numeric=['CN','RC','TI','CC'].includes(loginDocumentType.value);
      loginDocumentNumber.maxLength=20;
      loginDocumentNumber.inputMode=numeric?'numeric':'text';
      loginDocumentNumber.pattern=numeric?'[0-9]{1,20}':'[A-Za-z0-9]{1,20}';
    }
    loginDocumentType?.addEventListener('change',configurarReglasDocumentoIngreso);
    configurarReglasDocumentoIngreso();
const doctorSpecialty=document.getElementById('doctor-specialty');
const doctorClinic=document.getElementById('doctor-clinic');
// Muestra consultorios compatibles con la especialidad.
function filtrarConsultoriosMedico(){if(!doctorSpecialty||!doctorClinic)return;const selected=doctorSpecialty.value;[...doctorClinic.options].forEach(option=>{if(!option.value)return;const tienePermiso=(option.dataset.specialties||'').split(',');option.hidden=!tienePermiso.includes(selected);option.disabled=option.hidden;});if(doctorClinic.selectedOptions[0]?.disabled)doctorClinic.value='';}
doctorSpecialty?.addEventListener('change',filtrarConsultoriosMedico);filtrarConsultoriosMedico();


    document.querySelectorAll('input[name="telefono"],input[name="num_doc"],input[name="admin_code"]').forEach(input=>input.addEventListener('input',()=>{const max=input.name==='telefono'?10:(input.name==='admin_code'?8:20);if(input.name==='num_doc'){const select=input.form?.querySelector('[data-document-type],#login-document-type');const sigla=select?.id==='login-document-type'?select.value:select?.selectedOptions[0]?.textContent.trim().slice(0,2);input.value=['CN','RC','TI','CC'].includes(sigla)?input.value.replace(/\D/g,'').slice(0,max):input.value.replace(/[^a-z0-9]/gi,'').toUpperCase().slice(0,max);}else input.value=input.value.replace(/\D/g,'').slice(0,max);}));
document.querySelectorAll('input[data-rule="name"]').forEach(input=>input.addEventListener('input',()=>{const start=input.selectionStart;const clean=input.value.replace(/[^\p{L}\p{M} .'-]/gu,'');if(clean!==input.value){input.value=clean;input.setSelectionRange(Math.min(start,clean.length),Math.min(start,clean.length));}}));
document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===button.dataset.tab));}));
    document.querySelectorAll('main.container .panel').forEach(panel=>{
      if(panel.classList.contains('history-panel'))return;
      const heading=panel.querySelector(':scope > h2, :scope > .section-heading h2');
      if(!heading)return;
      const title=heading.textContent.trim();
      const disclosure=document.createElement('details');
      disclosure.className=panel.className+' panel-disclosure';
      const summary=document.createElement('summary');
      summary.textContent=title;
      const content=document.createElement('div');
      content.className='panel-disclosure-content';
      [...panel.childNodes].forEach(node=>{
        if(node===heading||node.nodeType===1&&node.classList?.contains('section-heading'))return;
        content.append(node);
      });
      const sectionHeading=panel.querySelector(':scope > .section-heading');
      if(sectionHeading){heading.remove();content.prepend(sectionHeading);}
      disclosure.append(summary,content);
      panel.replaceWith(disclosure);
    });
    document.querySelectorAll('[data-validate-form]').forEach(form=>{
      const type=form.querySelector('[data-document-type]'), number=form.querySelector('[data-document-number]');
      const fields=[...form.querySelectorAll('input,select,textarea')].filter(field=>field.type!=='hidden');
      const birth=form.querySelector('[name="fecha_nacimiento"]');
      // Ajusta la fecha máxima de nacimiento permitida.
function configurarLimiteNacimiento(){
        if(!birth)return;
        const sigla=type?.options[type.selectedIndex]?.textContent.trim().slice(0,2);
        const limit=new Date();
        limit.setHours(0,0,0,0);
        if(sigla==='CC'){
          limit.setFullYear(limit.getFullYear()-18);
          limit.setDate(limit.getDate()-1);
        }else limit.setDate(limit.getDate()-1);
        const year=limit.getFullYear(), month=String(limit.getMonth()+1).padStart(2,'0'), day=String(limit.getDate()).padStart(2,'0');
        birth.max=`${year}-${month}-${day}`;
      }
      // Ajusta el campo según el tipo de documento.
function configurarCampoDocumento(){
        if(!type||!number)return;
        const sigla=type.options[type.selectedIndex]?.textContent.trim().slice(0,2);
        const numeric=['CN','RC','TI','CC'].includes(sigla);
        number.maxLength=20;
        number.inputMode=numeric?'numeric':'text';
        number.pattern=numeric?'[0-9]{1,20}':'[A-Za-z0-9]{1,20}';
      }
      configurarCampoDocumento();
      configurarLimiteNacimiento();
      type?.addEventListener('change',configurarCampoDocumento);
      type?.addEventListener('change',configurarLimiteNacimiento);
      // Devuelve el mensaje de validación del campo.
function obtenerErrorDelCampo(field){
        const value=field.value.trim();
        if(field.required&&!value)return 'Completa este campo.';
        if(!value)return '';
        if(field.dataset.rule==='username'&&!/^[A-Za-z][A-Za-z._-]{3,49}$/.test(value))return 'Usa 4 a 50 letras; no escribas números.';
        if(field.dataset.rule==='name'&&!/^[\p{L}\p{M} .'-]+$/u.test(value))return 'Escribe solo letras, espacios o guiones.';
        if(field.dataset.rule==='password'&&value.length<8)return 'Escribe al menos 8 caracteres.';
        if(field.dataset.rule==='password-confirm'&&value!==form.querySelector('[name="password"]')?.value)return 'Las contraseñas deben coincidir.';
        if(field===number&&type){const sigla=type.options[type.selectedIndex]?.textContent.trim().slice(0,2);const numeric=['CN','RC','TI','CC'].includes(sigla);if(!(numeric?/^[0-9]{1,20}$/:/^[A-Za-z0-9]{1,20}$/).test(value))return numeric?'Este tipo de documento solo admite números.':'Usa de 1 a 20 letras o números, sin espacios ni puntos.';}
        if(field.type==='email'&&field.validity.typeMismatch)return 'Escribe un correo válido.';
        if(field.type==='number'&&Number(value)<0)return 'El valor no puede ser negativo.';
        if(field.type==='date'&&field.max&&value>field.max)return 'La fecha debe ser anterior a hoy y, para CC, corresponder a una persona mayor de 18 años.';
        if(field.validity.patternMismatch)return 'Revisa el formato indicado para este campo.';
        return '';
      }
      // Muestra si el campo tiene errores o está completo.
function actualizarValidacionVisual(field){const box=field.closest('.field');if(!box)return;const message=obtenerErrorDelCampo(field);box.classList.toggle('invalid',!!message);box.classList.toggle('valid',!message&&!!field.value);let note=box.querySelector('.validation-message');if(message){if(!note){note=document.createElement('small');note.className='validation-message';box.append(note);}note.textContent=message;}else if(note)note.remove();}
      fields.forEach(field=>{field.addEventListener('input',()=>{if(field.dataset.rule==='name'){const start=field.selectionStart;const clean=field.value.replace(/[^\p{L}\p{M} .'-]/gu,'');if(clean!==field.value){field.value=clean;field.setSelectionRange(Math.min(start,clean.length),Math.min(start,clean.length));}}actualizarValidacionVisual(field);if(field.name==='password')actualizarValidacionVisual(form.querySelector('[name="password_confirm"]'));});field.addEventListener('change',()=>{actualizarValidacionVisual(field);if(field===type){if(number)actualizarValidacionVisual(number);configurarLimiteNacimiento();if(birth)actualizarValidacionVisual(birth);}});field.addEventListener('blur',()=>actualizarValidacionVisual(field));});
      form.addEventListener('submit',event=>{fields.forEach(actualizarValidacionVisual);if(form.querySelector('.field.invalid')){event.preventDefault();form.querySelector('.field.invalid input,.field.invalid select')?.focus();}});
    });
    <?php if($action==='register'):?>document.querySelector('[data-tab="register"]').click();<?php endif?>
    </script></body></html><?php exit;
}

$role = (string)$_SESSION['role'];
$name = (string)$_SESSION['name'];
$dashboardError = '';
try {
    if ($role === 'paciente') {
        $query = $pdo->prepare('SELECT p.id_afiliado AS id_paciente,p.estado_afiliacion,t.nombre AS categoria,t.porcentaje_cobro FROM afiliado p LEFT JOIN tarifa t USING(id_tarifa) WHERE p.id_usuario=?');
        $query->execute([$_SESSION['user_id']]); $patient = $query->fetch();
        $query = $pdo->query("SELECT escaparHtml.id_horario,escaparHtml.fecha,escaparHtml.hora_inicio,m.nombre1||' '||m.apellido1 AS medico,e.nombre AS especialidad,e.id_especialidad,co.nombre AS consultorio FROM horario escaparHtml JOIN estado st ON st.id_estado=escaparHtml.id_estado JOIN medico m ON m.id_medico=escaparHtml.id_medico JOIN especialidad e ON e.id_especialidad=m.id_especialidad JOIN consultorio co ON co.id_consultorio=m.id_consultorio JOIN consultorio_especialidad ce ON ce.id_consultorio=m.id_consultorio AND ce.id_especialidad=m.id_especialidad WHERE st.ambito='horario' AND st.nombre='Disponible' AND escaparHtml.hora_inicio >= TIME '06:00' AND escaparHtml.hora_fin <= TIME '20:00' AND escaparHtml.hora_fin - escaparHtml.hora_inicio <= INTERVAL '1 hour' AND escaparHtml.fecha>CURRENT_DATE AND (escaparHtml.fecha+escaparHtml.hora_inicio)>CURRENT_TIMESTAMP ORDER BY escaparHtml.fecha,escaparHtml.hora_inicio"); $slots = $query->fetchAll();
        $services = $pdo->query("SELECT s.id_servicio,s.nombre,s.costo_base,COALESCE(string_agg(se.id_especialidad::text,',' ORDER BY se.id_especialidad),'') AS especialidades FROM servicio s LEFT JOIN servicio_especialidad se USING(id_servicio) GROUP BY s.id_servicio ORDER BY s.nombre")->fetchAll();
        $query = $pdo->prepare("SELECT c.id_cita,c.valor_cobrado,e.nombre AS estado,escaparHtml.fecha,escaparHtml.hora_inicio,s.nombre AS servicio,m.nombre1||' '||m.apellido1 AS medico,co.nombre AS consultorio,substring(hist.observaciones FROM 'Cita #' || c.id_cita || ': ([^\\n]*)') AS resultado FROM cita c JOIN estado e USING(id_estado) JOIN horario escaparHtml USING(id_horario) JOIN servicio s USING(id_servicio) JOIN medico m USING(id_medico) LEFT JOIN consultorio co ON co.id_consultorio=m.id_consultorio LEFT JOIN historial hist USING(id_afiliado) WHERE c.id_afiliado=? ORDER BY escaparHtml.fecha DESC,escaparHtml.hora_inicio DESC");
        $query->execute([$patient['id_paciente']]); $appointments = $query->fetchAll();
        $query = $pdo->prepare('SELECT alergias,grupo_sanguineo,antecedentes,observaciones FROM historial WHERE id_afiliado=?'); $query->execute([$patient['id_paciente']]); $history = $query->fetch();
    } elseif ($role === 'medico') {
        $query = $pdo->prepare("SELECT c.id_cita,e.nombre AS estado,escaparHtml.fecha,escaparHtml.hora_inicio,p.nombre1||' '||p.apellido1 AS paciente,td.sigla||' '||p.num_doc AS num_doc,p.telefono,s.nombre AS servicio,substring(hist.observaciones FROM 'Cita #' || c.id_cita || ': ([^\\n]*)') AS resultado,hist.alergias,hist.antecedentes,hist.grupo_sanguineo FROM medico m JOIN horario escaparHtml USING(id_medico) JOIN cita c USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado JOIN afiliado p USING(id_afiliado) JOIN tipo_documento td ON td.id_tipo_doc=p.id_tipo_doc JOIN servicio s USING(id_servicio) JOIN historial hist USING(id_afiliado) WHERE m.id_usuario=? AND e.nombre='Programada' ORDER BY escaparHtml.fecha,escaparHtml.hora_inicio");
        $query->execute([$_SESSION['user_id']]); $agenda = $query->fetchAll();
        $query = $pdo->prepare("SELECT c.id_cita,escaparHtml.fecha,escaparHtml.hora_inicio,p.nombre1||' '||p.apellido1 AS paciente,s.nombre AS servicio,substring(hist.observaciones FROM 'Cita #' || c.id_cita || ': ([^\\n]*)') AS resultado FROM medico m JOIN horario escaparHtml USING(id_medico) JOIN cita c USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado JOIN afiliado p USING(id_afiliado) JOIN servicio s USING(id_servicio) JOIN historial hist USING(id_afiliado) WHERE m.id_usuario=? AND e.nombre='Atendida' ORDER BY escaparHtml.fecha DESC,escaparHtml.hora_inicio DESC LIMIT 30");
        $query->execute([$_SESSION['user_id']]); $completedAppointments = $query->fetchAll();
    } else {
        $types = $pdo->query('SELECT id_tipo_doc,sigla,nombre FROM tipo_documento ORDER BY sigla')->fetchAll();
        $rawPatientSearchTerm = trim((string)($_GET['documento_busqueda'] ?? ''));
        $patientSearchTerm = preg_replace('/[^A-Z0-9]/', '', strtoupper($rawPatientSearchTerm)) ?? '';
        $searchTypeRaw = filter_var($_GET['tipo_documento_busqueda'] ?? null, FILTER_VALIDATE_INT);
        $patientSearchTypeId = $searchTypeRaw === false ? null : (int)$searchTypeRaw;
        $patientSearchTypeSigla = '';
        foreach ($types as $documentTypeOption) {
            if ((int)$documentTypeOption['id_tipo_doc'] === $patientSearchTypeId) {
                $patientSearchTypeSigla = (string)$documentTypeOption['sigla'];
                break;
            }
        }
        $searchSubmitted = isset($_GET['documento_busqueda']) || isset($_GET['tipo_documento_busqueda']);
        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM afiliado WHERE estado_afiliacion='Pendiente'")->fetchColumn();
        if ($searchSubmitted && $patientSearchTypeSigla === '') {
            $patientSearchError = 'Selecciona el tipo de documento del afiliado.';
        } elseif ($searchSubmitted && $patientSearchTerm === '') {
            $patientSearchError = 'Escribe el número de documento para buscar.';
        } elseif ($searchSubmitted && in_array($patientSearchTypeSigla, ['CN','RC','TI','CC'], true) && !preg_match('/^[0-9]{1,' . ($patientSearchTypeSigla === 'CC' ? '11' : '20') . '}$/', $patientSearchTerm)) {
            $patientSearchError = $patientSearchTypeSigla === 'CC'
                ? 'Para CC, escribe solo números y máximo 11 dígitos.'
                : 'Para este tipo de documento, escribe solo números (máximo 20 dígitos).';
        } elseif ($searchSubmitted && !preg_match('/^[A-Z0-9]{1,20}$/', $patientSearchTerm)) {
            $patientSearchError = 'El documento debe tener de 1 a 20 letras o números.';
        }
        if ($searchSubmitted && $patientSearchError === '') {
            $query = $pdo->prepare('SELECT p.id_afiliado AS id_paciente,p.id_usuario,p.id_tipo_doc,p.num_doc,p.nombre1,p.nombre2,p.apellido1,p.apellido2,p.sexo,p.fecha_nacimiento,p.telefono,p.correo,p.direccion,p.ingreso_mensual,p.estado_afiliacion,u.username,t.sigla,tr.nombre AS categoria FROM afiliado p JOIN usuario u USING(id_usuario) JOIN tipo_documento t USING(id_tipo_doc) LEFT JOIN tarifa tr USING(id_tarifa) WHERE p.id_tipo_doc=? AND p.num_doc=? ORDER BY p.apellido1,p.nombre1');
            $query->execute([$patientSearchTypeId, $patientSearchTerm]);
            $patients = $query->fetchAll();
            if (!$patients) $patientSearchError = 'No encontramos un afiliado con ese documento. Revisa el número e inténtalo otra vez.';
        } elseif (!$searchSubmitted) {
            $query = $pdo->query("SELECT p.id_afiliado AS id_paciente,p.id_usuario,p.id_tipo_doc,p.num_doc,p.nombre1,p.nombre2,p.apellido1,p.apellido2,p.sexo,p.fecha_nacimiento,p.telefono,p.correo,p.direccion,p.ingreso_mensual,p.estado_afiliacion,u.username,t.sigla,tr.nombre AS categoria FROM afiliado p JOIN usuario u USING(id_usuario) JOIN tipo_documento t USING(id_tipo_doc) LEFT JOIN tarifa tr USING(id_tarifa) WHERE p.estado_afiliacion='Pendiente' ORDER BY p.id_afiliado");
            $patients = $query->fetchAll();
        } else {
            $patients = [];
        }
        $appointmentQuery = $pdo->prepare("SELECT c.id_cita,escaparHtml.fecha,escaparHtml.hora_inicio,e.nombre AS estado,s.nombre AS servicio,m.nombre1||' '||m.apellido1 AS medico,COALESCE(co.nombre,'Sin asignar') AS consultorio FROM cita c JOIN horario escaparHtml USING(id_horario) JOIN estado e ON e.id_estado=c.id_estado AND e.ambito='cita' JOIN servicio s USING(id_servicio) JOIN medico m USING(id_medico) LEFT JOIN consultorio co ON co.id_consultorio=m.id_consultorio WHERE c.id_afiliado=? ORDER BY escaparHtml.fecha DESC,escaparHtml.hora_inicio DESC");
        foreach ($patients as &$affiliate) {
            $appointmentQuery->execute([(int)$affiliate['id_paciente']]);
            $affiliate['citas'] = $appointmentQuery->fetchAll();
        }
        unset($affiliate);
        $specialties = $pdo->query('SELECT id_especialidad,nombre FROM especialidad ORDER BY nombre')->fetchAll();
        $doctors = $pdo->query("SELECT m.id_medico,m.id_especialidad,m.nombre1,m.apellido1,m.num_documento AS num_doc,COALESCE(c.nombre,'Sin asignar') AS consultorio FROM medico m LEFT JOIN consultorio c ON c.id_consultorio=m.id_consultorio ORDER BY m.apellido1")->fetchAll();
        $clinics = $pdo->query("SELECT c.id_consultorio,c.nombre,COALESCE(string_agg(ce.id_especialidad::text, ','),'') AS especialidades FROM consultorio c LEFT JOIN consultorio_especialidad ce USING(id_consultorio) GROUP BY c.id_consultorio ORDER BY c.nombre")->fetchAll();
    }
} catch (Throwable $exception) {
    $dashboardError = 'No se pudieron cargar los datos del panel: ' . $exception->getMessage();
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EPS Villavicencio | Panel</title><link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="stylesheet" href="/assets/app.css"><style>
.panel-disclosure{padding:0;overflow:hidden}
.panel-disclosure>summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 24px;color:var(--blue-dark);cursor:pointer;font-size:1.08rem;font-weight:650;list-style:none}
.panel-disclosure>summary::-webkit-details-marker{display:none}
.panel-disclosure>summary::after{content:'+';display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:50%;background:#edf5f8;color:var(--blue-dark);font-size:1.25rem}
.panel-disclosure[open]>summary::after{content:'−'}
.panel-disclosure>summary:hover{background:#f7fafb}
.panel-disclosure-content{padding:0 24px 24px}
.panel-disclosure-content>.section-heading{margin-top:0}
@media(max-width:640px){.panel-disclosure>summary{padding:17px}.panel-disclosure-content{padding:0 17px 17px}}
</style></head><body class="app-page"><header class="app-header"><a class="brand" href="/"><span class="brand-symbol">+</span><span><strong>EPS Villavicencio</strong><small>Portal de citas médicas</small></span></a><div class="user-menu"><?=escaparHtml($name)?> · <?=escaparHtml(ucfirst($role))?> <a class="button secondary compact" href="/?salir=1">Salir</a></div></header><main class="container"><p class="eyebrow"><?=escaparHtml(strtoupper($role))?></p><h1><?= $role==='paciente'?'Mi portal':($role==='medico'?'Mi agenda':'Administración') ?></h1><?php if($message):?><div class="notice"><?=escaparHtml($message)?></div><?php endif?><?php if($error):?><div class="notice error"><?=escaparHtml($error)?></div><?php endif?><?php if($dashboardError):?><div class="notice error"><?=escaparHtml($dashboardError)?></div><?php endif?>
<?php if($role==='paciente'):?><section class="panel"><h2>Estado de afiliación: <?=escaparHtml($patient['estado_afiliacion']??'Sin perfil')?></h2><p>Categoría: <strong><?=escaparHtml($patient['categoria']??'Sin categoría')?></strong>.</p></section><div class="two-column"><section class="panel"><h2>Agendar una cita</h2><?php if(($patient['estado_afiliacion']??'')!=='Activo'):?><p class="notice warning">Tu afiliación está pendiente de revisión por la EPS.</p><?php elseif(!$slots):?><p>No hay horarios disponibles en este momento.</p><?php else:?><form method="post" class="appointment-form"><?=campoCsrf()?><input type="hidden" name="accion" value="reservar_cita"><label>Servicio</label><select name="id_servicio" id="service-select" required><option value="" selected disabled>Selecciona un servicio</option><?php foreach($services as $s):?><option value="<?=escaparHtml($s['id_servicio'])?>" data-cost="<?=escaparHtml($s['costo_base'])?>" data-specialties="<?=escaparHtml($s['especialidades'])?>"><?=escaparHtml($s['nombre'])?></option><?php endforeach?></select><p class="copay-note" id="copay-info" data-percentage="<?=escaparHtml($patient['porcentaje_cobro']??'0')?>">El copago se calcula después de elegir el servicio.</p><label>Horario, profesional y consultorio</label><select name="id_horario" id="slot-select" required disabled><option value="" selected disabled>Primero selecciona un servicio</option><?php foreach($slots as $s):?><option value="<?=escaparHtml($s['id_horario'])?>" data-specialty="<?=escaparHtml($s['id_especialidad'])?>"><?=escaparHtml($s['fecha'].' '.mostrarHoraFormato12($s['hora_inicio']).' · '.$s['medico'].' · '.$s['especialidad'].' · '.$s['consultorio'])?></option><?php endforeach?></select><button class="button primary">Reservar cita</button></form><?php endif?></section><section class="panel"><h2>Mi historia clínica</h2><p>Información que registraste al crear tu solicitud. Si necesitas corregirla, comunícate con la EPS.</p><p><strong>Alergias:</strong> <?=escaparHtml($history['alergias']??'Sin registrar')?></p><p><strong>Grupo sanguíneo:</strong> <?=escaparHtml($history['grupo_sanguineo']??'Sin registrar')?></p><p><strong>Antecedentes médicos:</strong> <?=escaparHtml($history['antecedentes']??'Sin registrar')?></p><p class="field-help">Los resultados de tus atenciones médicas se conservan por separado.</p></section></div><section class="panel"><h2>Mis citas</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Consultorio</th><th>Servicio</th><th>Valor</th><th>Estado</th><th>Resultado</th><th>Acción</th></tr></thead><tbody><?php foreach($appointments as $a):?><tr><td><?=escaparHtml($a['fecha'])?></td><td><?=escaparHtml(substr((string)$a['hora_inicio'],0,5))?></td><td><?=escaparHtml($a['medico'])?></td><td><?=escaparHtml($a['consultorio']??'Sin asignar')?></td><td><?=escaparHtml($a['servicio'])?></td><td>$<?=number_format((float)$a['valor_cobrado'],0,',','.')?></td><td><?=escaparHtml($a['estado'])?></td><td><?=escaparHtml($a['resultado']??'Pendiente')?></td><td><?php if($a['estado']==='Programada'):?><form method="post" onsubmit="return confirm('¿Cancelar esta cita?')"><?=campoCsrf()?><input type="hidden" name="accion" value="cancelar_cita"><input type="hidden" name="id_cita" value="<?=escaparHtml($a['id_cita'])?>"><button class="button secondary compact">Cancelar</button></form><?php else:?>—<?php endif?></td></tr><?php endforeach?></tbody></table></div></section>
<?php elseif($role==='medico'):?>
<section class="panel">
    <div class="section-heading"><div><h2>Citas pendientes de atención</h2><p>Al guardar el resultado, la cita pasa a Atendida y sale de esta lista.</p></div><span class="count-badge"><?=count($agenda)?></span></div>
    <?php if (!$agenda): ?><p class="empty-state">No tienes citas pendientes en tu agenda.</p><?php else: ?>
    <div class="table-wrap"><table class="agenda-table"><thead><tr><th>Fecha y hora</th><th>Paciente</th><th>Documento</th><th>Servicio</th><th>Historia resumida</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach($agenda as $a):?><tr><td><?=escaparHtml($a['fecha'].' · '.substr((string)$a['hora_inicio'],0,5))?></td><td><?=escaparHtml($a['paciente'])?></td><td><?=escaparHtml($a['num_doc'])?></td><td><?=escaparHtml($a['servicio'])?></td><td>Alergias: <?=escaparHtml($a['alergias']?:'—')?>; antecedentes: <?=escaparHtml($a['antecedentes']?:'—')?>; grupo: <?=escaparHtml($a['grupo_sanguineo']?:'—')?></td><td><form method="post" class="result-form"><?=campoCsrf()?><input type="hidden" name="accion" value="guardar_resultado"><input type="hidden" name="id_cita" value="<?=escaparHtml($a['id_cita'])?>"><textarea name="resultado" minlength="3" required placeholder="Escribe el resultado de la atención"><?=escaparHtml($a['resultado']??'')?></textarea><button class="button primary compact">Guardar y cerrar atención</button></form></td></tr><?php endforeach?>
    </tbody></table></div><?php endif?>
</section>
<details class="panel history-panel"><summary>Atenciones registradas (<?=count($completedAppointments)?>)</summary><p>Estas citas ya se marcaron como Atendidas; se conservan como historial y no vuelven a la lista pendiente.</p><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Servicio</th><th>Resultado</th></tr></thead><tbody><?php foreach($completedAppointments as $a):?><tr><td><?=escaparHtml($a['fecha'])?></td><td><?=escaparHtml(substr((string)$a['hora_inicio'],0,5))?></td><td><?=escaparHtml($a['paciente'])?></td><td><?=escaparHtml($a['servicio'])?></td><td><?=escaparHtml($a['resultado'])?></td></tr><?php endforeach?></tbody></table></div></details>
<?php else: ?>
<section class="panel">
    <div class="section-heading"><div><h2>Solicitudes y afiliaciones</h2><p>Las solicitudes pendientes aparecen aquí automáticamente. Activa la afiliación al confirmar el pago; la tarifa se asigna según el ingreso.</p></div><span class="count-badge" title="Solicitudes pendientes"><?=escaparHtml($pendingCount??0)?></span></div>
    <form method="get" class="inline-form"><label for="admin-affiliate-type">Tipo de documento</label><select id="admin-affiliate-type" name="tipo_documento_busqueda" required><option value="" disabled <?=empty($patientSearchTypeId)?'selected':''?>>Selecciona</option><?php foreach($types as $documentTypeOption):?><option value="<?=escaparHtml($documentTypeOption['id_tipo_doc'])?>" data-sigla="<?=escaparHtml($documentTypeOption['sigla'])?>" <?=(int)$patientSearchTypeId===(int)$documentTypeOption['id_tipo_doc']?'selected':''?>><?=escaparHtml($documentTypeOption['sigla'].' · '.$documentTypeOption['nombre'])?></option><?php endforeach?></select><label for="admin-affiliate-search">Número de documento</label><input type="search" id="admin-affiliate-search" name="documento_busqueda" value="<?=escaparHtml($patientSearchTerm)?>" maxlength="20" placeholder="Escribe el documento" required><button class="button primary compact">Buscar</button></form><?php if($patientSearchError):?><div class="notice error" role="status"><?=escaparHtml($patientSearchError)?></div><?php elseif(!$searchSubmitted):?><p class="field-help">Selecciona el tipo de documento. Para CC, escribe solo números (máximo 11).</p><?php endif?><div class="table-wrap"><table><thead><tr><th>Afiliado</th><th>Documento</th><th>Ingreso</th><th>Categoría</th><th>Estado</th><th>Actualizar estado</th><th>Citas</th><th>Datos</th></tr></thead><tbody>
    <?php if(!$patients):?><tr><td colspan="8" class="empty-state"><?=($searchSubmitted && !$patientSearchError)?'No hay afiliados con ese documento.':'No hay solicitudes pendientes de afiliación.'?></td></tr><?php endif?><?php foreach($patients as $p):?><tr><td><?=escaparHtml($p['nombre1'].' '.$p['apellido1'])?><small class="table-subtitle"><?=escaparHtml($p['username'])?></small></td><td><?=escaparHtml($p['sigla'].' '.$p['num_doc'])?></td><td>$<?=number_format((float)$p['ingreso_mensual'],0,',','.')?></td><td><?=escaparHtml($p['categoria']??'Sin categoría')?></td><td><span class="status-tag status-<?=escaparHtml(strtolower($p['estado_afiliacion']))?>"><?=escaparHtml($p['estado_afiliacion'])?></span></td><td><form method="post" class="inline-form"><?=campoCsrf()?><input type="hidden" name="accion" value="aprobar_afiliacion"><input type="hidden" name="id_paciente" value="<?=escaparHtml($p['id_paciente'])?>"><select name="estado_afiliacion" aria-label="Estado de afiliación"><option value="Pendiente" <?=$p['estado_afiliacion']==='Pendiente'?'selected':''?>>Pendiente</option><option value="Activo" <?=$p['estado_afiliacion']==='Activo'?'selected':''?>>Activo</option><option value="Inactivo" <?=$p['estado_afiliacion']==='Inactivo'?'selected':''?>>Inactivo</option></select><button class="button primary compact">Guardar</button></form></td><td><details><summary>Ver citas (<?=count($p['citas']??[])?>)</summary><?php if(empty($p['citas'])):?><p>Sin citas registradas.</p><?php else:?><ul><?php foreach($p['citas'] as $appointment):?><li><?=escaparHtml($appointment['fecha'].' '.substr((string)$appointment['hora_inicio'],0,5).' · '.$appointment['servicio'].' · '.$appointment['medico'].' · '.$appointment['consultorio'].' · '.$appointment['estado'])?></li><?php endforeach?></ul><?php endif?></details></td><td><details class="edit-details"><summary>Editar afiliado</summary><form method="post" class="form-grid admin-edit-form" data-validate-form><?=campoCsrf()?><input type="hidden" name="accion" value="actualizar_paciente"><input type="hidden" name="id_paciente" value="<?=escaparHtml($p['id_paciente'])?>"><div class="field"><label>Usuario</label><input name="username" value="<?=escaparHtml($p['username'])?>" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" data-document-type><?php foreach($types as $t):?><option value="<?=escaparHtml($t['id_tipo_doc'])?>" <?=$p['id_tipo_doc']===$t['id_tipo_doc']?'selected':''?>><?=escaparHtml($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número</label><input name="num_doc" value="<?=escaparHtml($p['num_doc'])?>" required maxlength="20" data-document-number></div><div class="field"><label>Primer nombre</label><input name="nombre1" data-rule="name" value="<?=escaparHtml($p['nombre1'])?>" required></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name" value="<?=escaparHtml($p['nombre2'])?>"></div><div class="field"><label>Primer apellido</label><input name="apellido1" data-rule="name" value="<?=escaparHtml($p['apellido1'])?>" required></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name" value="<?=escaparHtml($p['apellido2'])?>"></div><div class="field"><label>Sexo en documento de identidad</label><select name="sexo" required><option value="" disabled <?=$p['sexo']===null?'selected':''?>>Selecciona</option><?php foreach(['Femenino'=>'Femenino','Masculino'=>'Masculino','No binario'=>'No binario','Intersexual'=>'Intersexual','Otro'=>'Otro'] as $sexCode=>$sexName):?><option value="<?=escaparHtml($sexCode)?>" <?=mostrarSexoGuardado($p['sexo'])===$sexCode?'selected':''?>><?=escaparHtml($sexName)?></option><?php endforeach?></select></div><div class="field"><label>Nacimiento</label><input type="date" name="fecha_nacimiento" value="<?=escaparHtml($p['fecha_nacimiento'])?>" max="<?=date('Y-m-d', strtotime('-1 day'))?>" required></div><div class="field"><label>Ingreso mensual</label><input type="number" name="ingreso_mensual" min="0" step="1000" value="<?=escaparHtml($p['ingreso_mensual'])?>" required></div><div class="field"><label>Teléfono (máximo 10 dígitos)</label><input name="telefono" type="tel" inputmode="numeric" pattern="[0-9]{0,10}" maxlength="10" value="<?=escaparHtml($p['telefono'])?>"></div><div class="field"><label>Correo</label><input type="email" name="correo" value="<?=escaparHtml($p['correo'])?>"></div><div class="field full"><label>Dirección</label><input name="direccion" value="<?=escaparHtml($p['direccion'])?>"></div><div class="field full"><button class="button primary compact">Guardar datos del afiliado</button></div></form></details></td></tr><?php endforeach?>
    </tbody></table></div>
</section>
<div class="two-column">
<section class="panel"><h2>Registrar afiliado</h2><p>La cuenta se crea con estado Pendiente. La categoría se obtiene del ingreso mensual.</p><form method="post" class="form-grid validate-live" data-validate-form><?=campoCsrf()?><input type="hidden" name="accion" value="crear_paciente_admin"><div class="field"><label>Usuario (no es el documento)</label><input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Ej. ana.martinez" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"><small class="field-help">4–50 letras; no escribas aquí el número de documento.</small></div><div class="field"><label>Contraseña inicial</label><input name="password" type="password" autocomplete="new-password" minlength="8" required data-rule="password"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=escaparHtml($t['id_tipo_doc'])?>"><?=escaparHtml($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número de documento</label><input name="num_doc" required maxlength="20" data-document-number><small class="field-help">Sin espacios ni puntos; algunos tipos usan números y otros letras y números.</small></div><div class="field"><label>Primer nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Segundo nombre</label><input name="nombre2" data-rule="name"></div><div class="field"><label>Primer apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Segundo apellido</label><input name="apellido2" data-rule="name"></div><div class="field"><label>Sexo en documento de identidad</label><select name="sexo" required><option value="" selected disabled>Selecciona</option><?php foreach(['Femenino'=>'Femenino','Masculino'=>'Masculino','No binario'=>'No binario','Intersexual'=>'Intersexual','Otro'=>'Otro'] as $sexCode=>$sexName):?><option value="<?=escaparHtml($sexCode)?>"><?=escaparHtml($sexName)?></option><?php endforeach?></select></div><div class="field"><label>Fecha de nacimiento</label><input name="fecha_nacimiento" type="date" max="<?=date('Y-m-d', strtotime('-1 day'))?>" required></div><div class="field"><label>Ingreso mensual</label><input name="ingreso_mensual" type="number" min="0" step="1000" required></div><div class="field"><label>Teléfono (máximo 10 dígitos)</label><input name="telefono" type="tel" inputmode="numeric" pattern="[0-9]{0,10}" maxlength="10"></div><div class="field"><label>Correo</label><input name="correo" type="email"></div><div class="field full"><label>Dirección</label><input name="direccion"></div><div class="field full"><h3>Historia clínica</h3><small class="field-help">Registra estos datos para que aparezcan en el portal del afiliado. Si no tiene alergias o antecedentes, indícalo.</small></div><div class="field full"><label>Alergias</label><textarea name="alergias" maxlength="1000" placeholder="Si no tiene, escribe: No conocidas." required></textarea></div><div class="field"><label>Grupo sanguíneo</label><select name="grupo_sanguineo"><option value="">No lo sabe / Sin registrar</option><?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-','No sé'] as $bloodType):?><option value="<?=escaparHtml($bloodType)?>"><?=escaparHtml($bloodType)?></option><?php endforeach?></select></div><div class="field full"><label>Antecedentes médicos</label><textarea name="antecedentes" maxlength="2000" placeholder="Si no tiene, escribe: Ninguno." required></textarea></div><div class="field full"><button class="button primary">Crear usuario afiliado</button></div></form></section>
<section class="panel"><h2>Registrar médico</h2><form method="post" class="form-grid validate-live" data-validate-form><?=campoCsrf()?><input type="hidden" name="accion" value="crear_medico"><div class="field"><label>Usuario (no es el documento)</label><input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Ej. dr.garcia" required pattern="[A-Za-z][A-Za-z._-]{3,49}" data-rule="username"><small class="field-help">4–50 letras; no escribas aquí el número de documento.</small></div><div class="field"><label>Contraseña temporal</label><input name="password" type="password" autocomplete="new-password" minlength="8" required data-rule="password"></div><div class="field"><label>Tipo de documento</label><select name="id_tipo_doc" required data-document-type><?php foreach($types as $t):?><option value="<?=escaparHtml($t['id_tipo_doc'])?>"><?=escaparHtml($t['sigla'].' · '.$t['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Número</label><input name="num_doc" required maxlength="20" data-document-number></div><div class="field"><label>Nombre</label><input name="nombre1" required data-rule="name"></div><div class="field"><label>Apellido</label><input name="apellido1" required data-rule="name"></div><div class="field"><label>Correo</label><input type="email" name="correo"></div><div class="field"><label>Teléfono (máximo 10 dígitos)</label><input name="telefono" type="tel" inputmode="numeric" pattern="[0-9]{0,10}" maxlength="10"></div><div class="field"><label>Especialidad</label><select name="id_especialidad" id="doctor-specialty" required><?php foreach($specialties as $s):?><option value="<?=escaparHtml($s['id_especialidad'])?>"><?=escaparHtml($s['nombre'])?></option><?php endforeach?></select></div><div class="field"><label>Consultorio</label><select name="id_consultorio" id="doctor-clinic"><option value="">Sin asignar</option><?php foreach($clinics as $c):?><option value="<?=escaparHtml($c['id_consultorio'])?>" data-specialties="<?=escaparHtml($c['especialidades'])?>"><?=escaparHtml($c['nombre'])?></option><?php endforeach?></select></div><div class="field full"><button class="button primary">Registrar médico</button></div></form></section>
<section class="panel"><h2>Configurar horario</h2><form method="post" class="spaced-form"><?=campoCsrf()?><input type="hidden" name="accion" value="crear_horario"><label>Especialidad</label><select name="id_especialidad" id="schedule-specialty" required><option value="" selected disabled>Selecciona una especialidad</option><?php foreach($specialties as $specialty):?><option value="<?=escaparHtml($specialty['id_especialidad'])?>"><?=escaparHtml($specialty['nombre'])?></option><?php endforeach?></select><label>Médico y consultorio</label><select name="id_medico" id="schedule-doctor" required disabled><option value="" selected disabled>Primero selecciona una especialidad</option><?php foreach($doctors as $d):?><option value="<?=escaparHtml($d['id_medico'])?>" data-specialty="<?=escaparHtml($d['id_especialidad'])?>"><?=escaparHtml($d['nombre1'].' '.$d['apellido1'].' · '.$d['consultorio'])?></option><?php endforeach?></select><label>Fecha</label><input name="fecha" type="date" min="<?=date('Y-m-d')?>" required><div class="form-grid"><label>Hora de inicio<input name="hora_inicio" type="time" min="06:00" max="19:45" step="900" required></label><label>Hora de fin<input name="hora_fin" type="time" min="06:00" max="20:00" step="900" required></label></div><small class="field-help">Cada cita dura máximo una hora, entre 6:00 a. m. y 8:00 p. m.; usa intervalos de 15 minutos.</small><button class="button primary">Agregar horario</button></form></section>
</div><?php endif?></main>

<script>
const doctorSpecialty=document.getElementById('doctor-specialty');
const doctorClinic=document.getElementById('doctor-clinic');
function filtrarConsultoriosMedico(){if(!doctorSpecialty||!doctorClinic)return;const selected=doctorSpecialty.value;[...doctorClinic.options].forEach(option=>{if(!option.value)return;const tienePermiso=(option.dataset.specialties||'').split(',');option.hidden=!tienePermiso.includes(selected);option.disabled=option.hidden;});if(doctorClinic.selectedOptions[0]?.disabled)doctorClinic.value='';}
doctorSpecialty?.addEventListener('change',filtrarConsultoriosMedico);filtrarConsultoriosMedico();
const scheduleSpecialty=document.getElementById('schedule-specialty');const scheduleDoctor=document.getElementById('schedule-doctor');// Muestra los médicos de la especialidad elegida.
function filtrarMedicosPorEspecialidad(){if(!scheduleSpecialty||!scheduleDoctor)return;const selected=scheduleSpecialty.value;scheduleDoctor.disabled=!selected;[...scheduleDoctor.options].forEach(option=>{if(!option.value)return;const visible=!!selected&&option.dataset.specialty===selected;option.hidden=!visible;option.disabled=!visible;});scheduleDoctor.value='';}scheduleSpecialty?.addEventListener('change',filtrarMedicosPorEspecialidad);filtrarMedicosPorEspecialidad();
const scheduleForm=document.querySelector('form input[name="accion"][value="crear_horario"]')?.form;
const startTimeInput=scheduleForm?.querySelector('[name="hora_inicio"]');const endTimeInput=scheduleForm?.querySelector('[name="hora_fin"]');
// Limita la hora final según la hora de inicio.
function ajustarLimiteHoraFinal(){if(!startTimeInput||!endTimeInput)return;endTimeInput.setCustomValidity('');if(!startTimeInput.value){endTimeInput.min='06:00';endTimeInput.max='20:00';return;}const [hour,minute]=startTimeInput.value.split(':').map(Number);const start=hour*60+minute;const format=minutes=>`${String(Math.floor(minutes/60)).padStart(2,'0')}:${String(minutes%60).padStart(2,'0')}`;endTimeInput.min=format(start+15);endTimeInput.max=format(Math.min(start+60,1200));if(endTimeInput.value&&(endTimeInput.value<endTimeInput.min||endTimeInput.value>endTimeInput.max))endTimeInput.value='';}
startTimeInput?.addEventListener('input',ajustarLimiteHoraFinal);endTimeInput?.addEventListener('input',()=>endTimeInput.setCustomValidity(''));ajustarLimiteHoraFinal();
scheduleForm?.addEventListener('submit',event=>{if(!startTimeInput?.value||!endTimeInput?.value)return;const [sh,sm]=startTimeInput.value.split(':').map(Number);const [eh,em]=endTimeInput.value.split(':').map(Number);const duration=eh*60+em-(sh*60+sm);if(duration<=0||duration>60){endTimeInput.setCustomValidity('La cita debe durar entre 15 minutos y una hora.');endTimeInput.reportValidity();event.preventDefault();}});
const serviceSelect=document.getElementById('service-select');
const slotSelect=document.getElementById('slot-select');
const copayInfo=document.getElementById('copay-info');
if(serviceSelect&&slotSelect&&copayInfo){
  serviceSelect.addEventListener('change',()=>{
    const service=serviceSelect.options[serviceSelect.selectedIndex];
    const allowedSpecialties=new Set((service.dataset.specialties||'').split(',').filter(Boolean));
    let available=0;
    [...slotSelect.options].forEach(option=>{
      if(!option.value)return;
      const matches=allowedSpecialties.has(option.dataset.specialty||'');
      option.hidden=!matches;
      option.disabled=!matches;
      if(matches)available++;
    });
    slotSelect.value='';
    slotSelect.disabled=available===0;
    slotSelect.options[0].textContent=available?'Selecciona un horario':'No hay horarios de la especialidad para este servicio';
    const copay=Math.min(Number(service.dataset.cost)*Number(copayInfo.dataset.percentage)/100,10000);
    const money=new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(copay);
    copayInfo.textContent=`Copago: ${money}`;
  });
}
document.querySelectorAll('[data-validate-form]').forEach(form=>{
      const docType=form.querySelector('[data-document-type]');
  const docNumber=form.querySelector('[data-document-number]');
  const birth=form.querySelector('[name="fecha_nacimiento"]');
  const fields=[...form.querySelectorAll('input,select,textarea')].filter(input=>input.type!=='hidden');
      // Ajusta el formato del número de documento.
function configurarNumeroDocumento(){
        if(!docType||!docNumber)return;
        const sigla=docType.options[docType.selectedIndex]?.textContent.trim().slice(0,2);
        const numeric=['CN','RC','TI','CC'].includes(sigla);
        docNumber.inputMode=numeric?'numeric':'text';
        docNumber.pattern=numeric?'[0-9]{1,20}':'[A-Za-z0-9]{1,20}';
      }
      configurarNumeroDocumento();
  function configurarLimiteNacimiento(){
    if(!birth)return;
    const sigla=docType?.options[docType.selectedIndex]?.textContent.trim().slice(0,2);
    const limit=new Date();
    limit.setHours(0,0,0,0);
    if(sigla==='CC'){
      limit.setFullYear(limit.getFullYear()-18);
      limit.setDate(limit.getDate()-1);
    }else limit.setDate(limit.getDate()-1);
    const year=limit.getFullYear(), month=String(limit.getMonth()+1).padStart(2,'0'), day=String(limit.getDate()).padStart(2,'0');
    birth.max=`${year}-${month}-${day}`;
  }
  configurarLimiteNacimiento();
  // Explica qué debe corregirse en el campo.
function obtenerMensajeValidacion(field){
    const value=field.value.trim();
    if(field.required&&!value)return 'Completa este campo.';
    if(!value)return '';
    if(field.dataset.rule==='username'&&!/^[A-Za-z][A-Za-z._-]{3,49}$/.test(value))return 'Usa 4 a 50 letras; no escribas números.';
    if(field.dataset.rule==='name'&&!/^[\p{L}\p{M} .'-]+$/u.test(value))return 'Escribe solo letras, espacios o guiones.';
    if(field.dataset.rule==='password'&&value.length<8)return 'Escribe al menos 8 caracteres.';
    if(field.dataset.rule==='password-confirm'&&value!==form.querySelector('[name="password"]')?.value)return 'Las contraseñas deben coincidir.';
    if(field===docNumber&&docType){const sigla=docType.options[docType.selectedIndex]?.textContent.trim().slice(0,2);const numeric=['CN','RC','TI','CC'].includes(sigla);if(!(numeric?/^[0-9]{1,20}$/:/^[A-Za-z0-9]{1,20}$/).test(value))return numeric?'Este tipo de documento solo admite números.':'Usa de 1 a 20 letras o números, sin espacios ni puntos.';}
    if(field.type==='email'&&field.validity.typeMismatch)return 'Escribe un correo válido.';
    if(field.type==='number'&&Number(value)<0)return 'El valor no puede ser negativo.';
    if(field.type==='date'&&field.max&&value>field.max)return 'La fecha debe ser anterior a hoy y, para CC, corresponder a una persona mayor de 18 años.';
    if(field.validity.patternMismatch)return 'Revisa el formato indicado para este campo.';
    return '';
  }
  function actualizarValidacionVisual(field){if(!field.closest('.field'))return;const box=field.closest('.field');const msg=obtenerMensajeValidacion(field);box.classList.toggle('invalid',!!msg);box.classList.toggle('valid',!msg&&!!field.value);let note=box.querySelector('.validation-message');if(msg){if(!note){note=document.createElement('small');note.className='validation-message';box.append(note);}note.textContent=msg;}else if(note)note.remove();}
  fields.forEach(field=>{field.addEventListener('input',()=>{actualizarValidacionVisual(field);if(field.name==='password')actualizarValidacionVisual(form.querySelector('[name="password_confirm"]'));});field.addEventListener('change',()=>{actualizarValidacionVisual(field);if(field===docType){configurarNumeroDocumento();if(docNumber)actualizarValidacionVisual(docNumber);configurarLimiteNacimiento();if(birth)actualizarValidacionVisual(birth);}});field.addEventListener('blur',()=>actualizarValidacionVisual(field));});
  form.addEventListener('submit',event=>{fields.forEach(actualizarValidacionVisual);if(form.querySelector('.field.invalid')){event.preventDefault();form.querySelector('.field.invalid input,.field.invalid select')?.focus();}});
});
document.querySelectorAll('input[name="telefono"],input[name="num_doc"],input[name="admin_code"]').forEach(input=>input.addEventListener('input',()=>{const max=input.name==='telefono'?10:(input.name==='admin_code'?8:20);if(input.name==='num_doc'){const select=input.form?.querySelector('[data-document-type],#login-document-type');const sigla=select?.id==='login-document-type'?select.value:select?.selectedOptions[0]?.textContent.trim().slice(0,2);input.value=['CN','RC','TI','CC'].includes(sigla)?input.value.replace(/\D/g,'').slice(0,max):input.value.replace(/[^a-z0-9]/gi,'').toUpperCase().slice(0,max);}else input.value=input.value.replace(/\D/g,'').slice(0,max);}));
const affiliateSearchType=document.getElementById('admin-affiliate-type'),affiliateSearchInput=document.getElementById('admin-affiliate-search');
// Ajusta la búsqueda según el tipo de documento.
function configurarBusquedaAfiliado(){if(!affiliateSearchType||!affiliateSearchInput)return;const sigla=affiliateSearchType.selectedOptions[0]?.dataset.sigla||'';const numeric=['CN','RC','TI','CC'].includes(sigla);const max=sigla==='CC'?11:20;affiliateSearchInput.maxLength=max;affiliateSearchInput.inputMode=numeric?'numeric':'text';affiliateSearchInput.pattern=numeric?`[0-9]{1,${max}}`:'[A-Za-z0-9]{1,20}';affiliateSearchInput.value=(numeric?affiliateSearchInput.value.replace(/\D/g,''):affiliateSearchInput.value.replace(/[^a-z0-9]/gi,'').toUpperCase()).slice(0,max);}
affiliateSearchType?.addEventListener('change',configurarBusquedaAfiliado);affiliateSearchInput?.addEventListener('input',configurarBusquedaAfiliado);configurarBusquedaAfiliado();
document.querySelectorAll('input[data-rule="name"]').forEach(input=>input.addEventListener('input',()=>{const start=input.selectionStart;const clean=input.value.replace(/[^\p{L}\p{M} .'-]/gu,'');if(clean!==input.value){input.value=clean;input.setSelectionRange(Math.min(start,clean.length),Math.min(start,clean.length));}}));
document.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===button.dataset.tab));}));
    <?php if($action==='register'):?>document.querySelector('[data-tab="register"]')?.click();<?php endif?>
</script></body></html>
