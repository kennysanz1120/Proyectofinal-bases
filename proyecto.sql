-- ================================================================
-- EPS Villavicencio | Script único de creación y datos de ejemplo
-- Ejecutar una vez en una base de datos vacía usando psql.
-- Incluye el rol local de PostgreSQL, tablas, catálogos, permisos,
-- restricciones, funciones, disparadores y 23 cuentas de demostración.
-- ================================================================

-- 1. Cuenta de conexión local de la aplicación
-- PostgreSQL pedirá la contraseña en la terminal.
SELECT 'CREATE ROLE eps_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'eps_app') \gexec
\password eps_app

-- 2. Roles funcionales y permisos de la aplicación
CREATE TABLE roles (
    id_rol SMALLSERIAL PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(50) NOT NULL
);

CREATE TABLE permisos (
    id_permiso SMALLSERIAL PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    descripcion VARCHAR(150) NOT NULL
);

CREATE TABLE rol_permiso (
    id_rol SMALLINT NOT NULL REFERENCES roles(id_rol),
    id_permiso SMALLINT NOT NULL REFERENCES permisos(id_permiso),
    PRIMARY KEY (id_rol, id_permiso)
);

INSERT INTO roles (codigo, nombre) VALUES
    ('paciente', 'Paciente'),
    ('medico', 'Médico'),
    ('administrador', 'Administrador');

INSERT INTO permisos (codigo, descripcion) VALUES
    ('ver_datos_propios', 'Consultar los datos propios'),
    ('reservar_cita', 'Reservar una franja disponible'),
    ('ver_agenda', 'Consultar la agenda médica propia'),
    ('registrar_resultado', 'Registrar el resultado de una cita propia'),
    ('gestionar_pacientes', 'Consultar y actualizar afiliaciones'),
    ('gestionar_medicos', 'Crear y actualizar perfiles médicos'),
    ('gestionar_horarios', 'Crear y actualizar horarios de atención');

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r CROSS JOIN permisos p
WHERE (r.codigo = 'paciente' AND p.codigo IN ('ver_datos_propios', 'reservar_cita'))
   OR (r.codigo = 'medico' AND p.codigo IN ('ver_agenda', 'registrar_resultado'))
   OR (r.codigo = 'administrador' AND p.codigo IN ('gestionar_pacientes', 'gestionar_medicos', 'gestionar_horarios'));

-- 3. Tablas de catálogo
CREATE TABLE tipo_documento (
    id_tipo_doc SMALLSERIAL PRIMARY KEY,
    sigla VARCHAR(5) NOT NULL UNIQUE,
    nombre VARCHAR(60) NOT NULL
);

CREATE TABLE estado (
    id_estado SMALLSERIAL PRIMARY KEY,
    ambito VARCHAR(20) NOT NULL,
    nombre VARCHAR(30) NOT NULL,
    UNIQUE (ambito, nombre)
);

CREATE TABLE tarifa (
    id_tarifa SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    ingreso_min NUMERIC(12,2) NOT NULL CHECK (ingreso_min >= 0),
    ingreso_max NUMERIC(12,2) NOT NULL CHECK (ingreso_max >= ingreso_min),
    porcentaje_cobro NUMERIC(5,2) NOT NULL CHECK (porcentaje_cobro BETWEEN 0 AND 100)
);

CREATE TABLE especialidad (
    id_especialidad SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE
);

CREATE TABLE servicio (
    id_servicio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion TEXT,
    costo_base NUMERIC(10,2) NOT NULL CHECK (costo_base >= 0),
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad)
);

CREATE TABLE consultorio (
    id_consultorio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    ubicacion VARCHAR(100) NOT NULL,
    capacidad SMALLINT NOT NULL CHECK (capacidad >= 0)
);

-- 4. Credenciales y perfiles personales
CREATE TABLE usuarios (
    id_usuario SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    id_rol SMALLINT NOT NULL REFERENCES roles(id_rol),
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE pacientes (
    id_paciente SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_doc VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'),
    nombre2 VARCHAR(50) CHECK (nombre2 IS NULL OR nombre2 !~ '[0-9]'),
    apellido1 VARCHAR(50) NOT NULL CHECK (apellido1 !~ '[0-9]'),
    apellido2 VARCHAR(50) CHECK (apellido2 IS NULL OR apellido2 !~ '[0-9]'),
    sexo VARCHAR(20),
    fecha_nacimiento DATE NOT NULL CHECK (fecha_nacimiento <= CURRENT_DATE),
    telefono VARCHAR(20),
    correo VARCHAR(100),
    direccion VARCHAR(150),
    ingreso_mensual NUMERIC(12,2) NOT NULL CHECK (ingreso_mensual >= 0),
    id_tarifa SMALLINT REFERENCES tarifa(id_tarifa),
    estado_afiliacion VARCHAR(12) NOT NULL DEFAULT 'Pendiente'
        CHECK (estado_afiliacion IN ('Pendiente', 'Activo', 'Inactivo')),
    UNIQUE (id_tipo_doc, num_doc)
);

CREATE TABLE medicos (
    id_medico SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_doc VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'),
    nombre2 VARCHAR(50) CHECK (nombre2 IS NULL OR nombre2 !~ '[0-9]'),
    apellido1 VARCHAR(50) NOT NULL CHECK (apellido1 !~ '[0-9]'),
    apellido2 VARCHAR(50) CHECK (apellido2 IS NULL OR apellido2 !~ '[0-9]'),
    telefono VARCHAR(20),
    correo VARCHAR(100),
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad),
    id_consultorio SMALLINT REFERENCES consultorio(id_consultorio),
    UNIQUE (id_tipo_doc, num_doc)
);

CREATE TABLE historial (
    id_historial SERIAL PRIMARY KEY,
    id_paciente INTEGER NOT NULL UNIQUE REFERENCES pacientes(id_paciente) ON DELETE CASCADE,
    fecha_apertura DATE NOT NULL DEFAULT CURRENT_DATE,
    alergias TEXT,
    grupo_sanguineo VARCHAR(5),
    antecedentes TEXT,
    observaciones TEXT
);

-- 5. Horarios, citas y resultados
CREATE TABLE horario (
    id_horario SERIAL PRIMARY KEY,
    id_medico INTEGER NOT NULL REFERENCES medicos(id_medico),
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    CHECK (hora_fin > hora_inicio),
    UNIQUE (id_medico, fecha, hora_inicio)
);

CREATE TABLE cita (
    id_cita SERIAL PRIMARY KEY,
    id_paciente INTEGER NOT NULL REFERENCES pacientes(id_paciente),
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio),
    id_horario INTEGER NOT NULL UNIQUE REFERENCES horario(id_horario),
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    valor_cobrado NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (valor_cobrado >= 0)
);

CREATE TABLE resultado_consulta (
    id_resultado SERIAL PRIMARY KEY,
    id_cita INTEGER NOT NULL UNIQUE REFERENCES cita(id_cita) ON DELETE CASCADE,
    id_medico INTEGER NOT NULL REFERENCES medicos(id_medico),
    resultado TEXT NOT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tipo_documento (sigla, nombre) VALUES
    ('CC', 'Cédula de ciudadanía'),
    ('TI', 'Tarjeta de identidad'),
    ('RC', 'Registro civil'),
    ('CE', 'Cédula de extranjería'),
    ('PA', 'Pasaporte');

INSERT INTO estado (ambito, nombre) VALUES
    ('horario', 'Disponible'),
    ('horario', 'Ocupado'),
    ('cita', 'Programada'),
    ('cita', 'Atendida'),
    ('cita', 'Cancelada');

INSERT INTO tarifa (nombre, ingreso_min, ingreso_max, porcentaje_cobro) VALUES
    ('Categoría A', 0, 1000000, 0),
    ('Categoría B', 1000000.01, 2500000, 5),
    ('Categoría C', 2500000.01, 9999999999.99, 10);

INSERT INTO especialidad (nombre) VALUES
    ('Medicina general'), ('Pediatría'), ('Odontología'),
    ('Cardiología'), ('Dermatología'), ('Ginecología y obstetricia'),
    ('Oftalmología'), ('Ortopedia'), ('Medicina interna'),
    ('Psicología'), ('Nutrición'), ('Enfermería');

INSERT INTO servicio (nombre, descripcion, costo_base, id_especialidad)
SELECT datos.nombre, datos.descripcion, datos.costo, e.id_especialidad
  FROM (VALUES
    ('Medicina general', 'Consulta médica general', 50000::NUMERIC, 'Medicina general'),
    ('Control de medicina general', 'Seguimiento de consulta general', 40000, 'Medicina general'),
    ('Consulta preventiva', 'Valoración preventiva de salud', 45000, 'Medicina general'),
    ('Pediatría', 'Atención médica infantil', 70000, 'Pediatría'),
    ('Control de crecimiento y desarrollo', 'Seguimiento infantil', 60000, 'Pediatría'),
    ('Odontología general', 'Valoración odontológica', 60000, 'Odontología'),
    ('Limpieza dental', 'Profilaxis y limpieza dental', 80000, 'Odontología'),
    ('Cardiología', 'Consulta de salud cardiovascular', 100000, 'Cardiología'),
    ('Dermatología', 'Consulta de piel', 90000, 'Dermatología'),
    ('Ginecología', 'Consulta ginecológica', 90000, 'Ginecología y obstetricia'),
    ('Oftalmología', 'Valoración de salud visual', 85000, 'Oftalmología'),
    ('Ortopedia', 'Valoración osteomuscular', 95000, 'Ortopedia'),
    ('Medicina interna', 'Consulta de medicina interna', 90000, 'Medicina interna'),
    ('Psicología', 'Consulta psicológica', 80000, 'Psicología'),
    ('Nutrición', 'Consulta nutricional', 65000, 'Nutrición'),
    ('Enfermería', 'Procedimientos básicos de enfermería', 35000, 'Enfermería')
  ) AS datos(nombre, descripcion, costo, especialidad)
  JOIN especialidad e ON e.nombre = datos.especialidad;

INSERT INTO consultorio (nombre, ubicacion, capacidad) VALUES
    ('Consultorio 101', 'Piso 1', 3),
    ('Consultorio 102', 'Piso 1', 3),
    ('Consultorio 201', 'Piso 2', 2),
    ('Consultorio 103', 'Piso 1', 2),
    ('Consultorio 202', 'Piso 2', 2),
    ('Consultorio 203', 'Piso 2', 2),
    ('Consultorio 301', 'Piso 3', 2);

-- 6. Funciones y disparadores sencillos
-- Calcula el copago por servicio y categoría, con un máximo de $10.000.
CREATE FUNCTION calcular_valor_cita() RETURNS TRIGGER AS $$
DECLARE
    costo NUMERIC(10,2);
    porcentaje NUMERIC(5,2);
BEGIN
    SELECT s.costo_base, t.porcentaje_cobro
      INTO costo, porcentaje
      FROM servicio s
      JOIN pacientes p ON p.id_paciente = NEW.id_paciente
      JOIN tarifa t ON t.id_tarifa = p.id_tarifa
     WHERE s.id_servicio = NEW.id_servicio;

    IF costo IS NULL OR porcentaje IS NULL THEN
        RAISE EXCEPTION 'No se encontró el servicio o la tarifa del paciente.';
    END IF;

    NEW.valor_cobrado := LEAST(ROUND(costo * porcentaje / 100, 2), 10000.00);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_calcular_valor_cita
BEFORE INSERT OR UPDATE OF id_servicio, id_paciente ON cita
FOR EACH ROW EXECUTE FUNCTION calcular_valor_cita();

-- El disparador marca como ocupado el horario reservado y lo libera al borrar la cita.
CREATE FUNCTION actualizar_estado_horario() RETURNS TRIGGER AS $$
DECLARE
    horario_actual INTEGER;
    estado_actual SMALLINT;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        horario_actual := OLD.id_horario;
        SELECT id_estado INTO estado_actual FROM estado
         WHERE ambito = 'horario'
           AND nombre = CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario = horario_actual)
                             THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado = estado_actual WHERE id_horario = horario_actual;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        horario_actual := NEW.id_horario;
        SELECT id_estado INTO estado_actual FROM estado
         WHERE ambito = 'horario'
           AND nombre = CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario = horario_actual)
                             THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado = estado_actual WHERE id_horario = horario_actual;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_actualizar_estado_horario
AFTER INSERT OR UPDATE OR DELETE ON cita
FOR EACH ROW EXECUTE FUNCTION actualizar_estado_horario();

-- 7. Permisos de conexión de la aplicación local
GRANT USAGE ON SCHEMA public TO eps_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO eps_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO eps_app;

-- 8. Veintitrés cuentas iniciales: un administrador, siete médicos y quince pacientes.
-- Todas usan la contraseña de demostración: DemoEPS2026
INSERT INTO usuarios (username, password_hash, id_rol)
SELECT datos.username,
       '$2y$10$pjW3frTlI0e5915EpCulFeMkeBNpu/a9TUgntvPNtq9LGjrSgDJkC',
       r.id_rol
  FROM (VALUES
    ('admin.eps', 'administrador'),
    ('diana.rojas', 'medico'), ('carlos.mejia', 'medico'),
    ('lucia.torres', 'medico'), ('andres.mora', 'medico'),
    ('carolina.gil', 'medico'), ('felipe.ruiz', 'medico'),
    ('natalia.santos', 'medico'),
    ('ana.martinez', 'paciente'), ('luis.gomez', 'paciente'),
    ('laura.ramirez', 'paciente'), ('miguel.perez', 'paciente'),
    ('sofia.lopez', 'paciente'), ('juan.hernandez', 'paciente'),
    ('valentina.diaz', 'paciente'), ('mateo.castillo', 'paciente'),
    ('isabella.vargas', 'paciente'), ('santiago.ruiz', 'paciente'),
    ('camila.moreno', 'paciente'), ('daniel.ortiz', 'paciente'),
    ('gabriela.silva', 'paciente'), ('samuel.reyes', 'paciente'),
    ('mariana.castro', 'paciente')
  ) AS datos(username, rol)
  JOIN roles r ON r.codigo = datos.rol;

-- Perfiles de los siete médicos de demostración.
INSERT INTO medicos (id_usuario, id_tipo_doc, num_doc, nombre1, apellido1, telefono, correo, id_especialidad, id_consultorio)
SELECT u.id_usuario, td.id_tipo_doc, datos.documento, datos.nombre, datos.apellido,
       '3005550100', datos.username || '@eps.local', e.id_especialidad, c.id_consultorio
  FROM (VALUES
    ('diana.rojas', '90000001', 'Diana', 'Rojas', 'Medicina general', 'Consultorio 101'),
    ('carlos.mejia', '90000002', 'Carlos', 'Mejía', 'Pediatría', 'Consultorio 102'),
    ('lucia.torres', '90000003', 'Lucía', 'Torres', 'Odontología', 'Consultorio 201'),
    ('andres.mora', '90000004', 'Andrés', 'Mora', 'Medicina general', 'Consultorio 101'),
    ('carolina.gil', '90000005', 'Carolina', 'Gil', 'Cardiología', 'Consultorio 202'),
    ('felipe.ruiz', '90000006', 'Felipe', 'Ruiz', 'Dermatología', 'Consultorio 203'),
    ('natalia.santos', '90000007', 'Natalia', 'Santos', 'Ginecología y obstetricia', 'Consultorio 301')
  ) AS datos(username, documento, nombre, apellido, especialidad, consultorio)
  JOIN usuarios u ON u.username = datos.username
  JOIN tipo_documento td ON td.sigla = 'CC'
  JOIN especialidad e ON e.nombre = datos.especialidad
  JOIN consultorio c ON c.nombre = datos.consultorio;

-- Perfiles de quince pacientes; diez afiliaciones están activas para probar reservas.
INSERT INTO pacientes (id_usuario, id_tipo_doc, num_doc, nombre1, apellido1, fecha_nacimiento, telefono, correo, direccion, ingreso_mensual, id_tarifa, estado_afiliacion)
SELECT u.id_usuario, td.id_tipo_doc, datos.documento, datos.nombre, datos.apellido,
       DATE '1995-01-01' + datos.n, '300555' || lpad(datos.n::text, 4, '0'),
       datos.username || '@correo.local', 'Villavicencio', datos.ingreso,
       t.id_tarifa,
       CASE WHEN datos.n <= 10 THEN 'Activo' ELSE 'Pendiente' END
  FROM (VALUES
    ('ana.martinez','10000001','Ana','Martínez',1,800000), ('luis.gomez','10000002','Luis','Gómez',2,800000),
    ('laura.ramirez','10000003','Laura','Ramírez',3,800000), ('miguel.perez','10000004','Miguel','Pérez',4,800000),
    ('sofia.lopez','10000005','Sofía','López',5,800000), ('juan.hernandez','10000006','Juan','Hernández',6,1800000),
    ('valentina.diaz','10000007','Valentina','Díaz',7,1800000), ('mateo.castillo','10000008','Mateo','Castillo',8,1800000),
    ('isabella.vargas','10000009','Isabella','Vargas',9,1800000), ('santiago.ruiz','10000010','Santiago','Ruiz',10,1800000),
    ('camila.moreno','10000011','Camila','Moreno',11,3200000), ('daniel.ortiz','10000012','Daniel','Ortiz',12,3200000),
    ('gabriela.silva','10000013','Gabriela','Silva',13,3200000), ('samuel.reyes','10000014','Samuel','Reyes',14,3200000),
    ('mariana.castro','10000015','Mariana','Castro',15,3200000)
  ) AS datos(username, documento, nombre, apellido, n, ingreso)
  JOIN usuarios u ON u.username = datos.username
  JOIN tipo_documento td ON td.sigla = 'CC'
  JOIN tarifa t ON datos.ingreso BETWEEN t.ingreso_min AND t.ingreso_max;

INSERT INTO historial (id_paciente, alergias, grupo_sanguineo, antecedentes)
SELECT id_paciente, 'Sin alergias registradas', 'O+', 'Sin antecedentes registrados'
  FROM pacientes;

-- Horarios para los médicos de demostración y dos citas de ejemplo.
INSERT INTO horario (id_medico, fecha, hora_inicio, hora_fin, id_estado)
SELECT m.id_medico, CURRENT_DATE + 1, TIME '08:00' + (g.slot * INTERVAL '1 hour'),
       TIME '09:00' + (g.slot * INTERVAL '1 hour'),
       (SELECT id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible')
  FROM medicos m CROSS JOIN generate_series(0, 2) AS g(slot)
 ORDER BY m.id_medico, g.slot;

INSERT INTO cita (id_paciente, id_servicio, id_horario, id_estado)
SELECT p.id_paciente, s.id_servicio, h.id_horario,
       (SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Programada')
  FROM pacientes p
  JOIN usuarios u ON u.id_usuario = p.id_usuario
  JOIN horario h ON h.id_horario = p.id_paciente - (SELECT min(id_paciente) FROM pacientes) + 1
  JOIN servicio s ON s.nombre = 'Medicina general'
 WHERE u.username IN ('ana.martinez', 'luis.gomez');

-- Descripciones básicas para facilitar la sustentación del modelo.
COMMENT ON TABLE usuarios IS 'Credenciales de acceso al portal.';
COMMENT ON TABLE roles IS 'Perfiles de paciente, médico y administrador.';
COMMENT ON TABLE permisos IS 'Acciones disponibles para los perfiles del portal.';
COMMENT ON TABLE pacientes IS 'Datos personales y estado de afiliación de cada paciente.';
COMMENT ON COLUMN pacientes.estado_afiliacion IS 'Pendiente hasta que la EPS confirme el pago externo.';
COMMENT ON TABLE medicos IS 'Datos personales y profesionales de cada médico.';
COMMENT ON TABLE cita IS 'Reserva de un paciente para un servicio y horario.';
COMMENT ON COLUMN servicio.costo_base IS 'Costo base interno usado para calcular el copago; no se muestra antes de elegir el servicio.';
COMMENT ON COLUMN servicio.id_especialidad IS 'Especialidad médica que presta el servicio y permite filtrar sus horarios.';
