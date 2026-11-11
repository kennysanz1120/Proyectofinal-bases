-- Roles PostgreSQL: eps_app es la conexión de la aplicación; los demás
-- son grupos para accesos directos diferenciados a la base de datos.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_app') THEN
        CREATE ROLE eps_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '2345';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_lectura') THEN CREATE ROLE eps_lectura NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_paciente') THEN CREATE ROLE eps_paciente NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_medico') THEN CREATE ROLE eps_medico NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='eps_administrador') THEN CREATE ROLE eps_administrador NOLOGIN; END IF;
END $$;
ALTER ROLE eps_app WITH LOGIN PASSWORD '2345';

BEGIN;




-- Catálogos del MER
CREATE TABLE tipo_documento (
    id_tipo_doc SMALLSERIAL PRIMARY KEY,
    sigla VARCHAR(5) NOT NULL UNIQUE,
    nombre VARCHAR(60) NOT NULL
);

CREATE TABLE estado (
    id_estado SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL,
    ambito VARCHAR(20) NOT NULL,
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
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion TEXT
);

CREATE TABLE servicio (
    id_servicio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion TEXT,
    costo_base NUMERIC(10,2) NOT NULL CHECK (costo_base >= 0)
);

CREATE TABLE servicio_especialidad (
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio) ON DELETE CASCADE,
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE RESTRICT,
    PRIMARY KEY (id_servicio,id_especialidad)
);

CREATE TABLE consultorio (
    id_consultorio SMALLSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    ubicacion VARCHAR(100) NOT NULL,
    capacidad SMALLINT NOT NULL CHECK (capacidad > 0),
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado)
);

CREATE TABLE consultorio_especialidad (
    id_consultorio SMALLINT NOT NULL REFERENCES consultorio(id_consultorio) ON DELETE CASCADE,
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE RESTRICT,
    PRIMARY KEY (id_consultorio, id_especialidad)
);

-- Entidad solicitada para las credenciales del portal.
CREATE TABLE usuario (
    id_usuario SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- Entidades afiliado y médico del MER; usuario las habilita para iniciar sesión.
CREATE TABLE afiliado (
    id_afiliado SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_doc VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'), -- rechaza numeros
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
    estado_afiliacion VARCHAR(12) NOT NULL DEFAULT 'Pendiente'--inicia en pendiente
        CHECK (estado_afiliacion IN ('Pendiente','Activo','Inactivo')),--solo recibe esos estados
    UNIQUE (id_tipo_doc, num_doc)--impide repetir el mismo número para el mismo tipo de documento
);

CREATE TABLE medico (
    id_medico SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL UNIQUE REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento(id_tipo_doc),
    num_documento VARCHAR(20) NOT NULL,
    nombre1 VARCHAR(50) NOT NULL CHECK (nombre1 !~ '[0-9]'),
    nombre2 VARCHAR(50) CHECK (nombre2 IS NULL OR nombre2 !~ '[0-9]'),
    apellido1 VARCHAR(50) NOT NULL CHECK (apellido1 !~ '[0-9]'),
    apellido2 VARCHAR(50) CHECK (apellido2 IS NULL OR apellido2 !~ '[0-9]'),
    correo VARCHAR(100),
    telefono VARCHAR(20),
    id_especialidad SMALLINT NOT NULL REFERENCES especialidad(id_especialidad),
    id_consultorio SMALLINT,
    UNIQUE (id_tipo_doc, num_documento),
    FOREIGN KEY (id_consultorio, id_especialidad)
        REFERENCES consultorio_especialidad(id_consultorio, id_especialidad)
);

CREATE TABLE historial (
    id_historial SERIAL PRIMARY KEY,
    id_afiliado INTEGER NOT NULL UNIQUE REFERENCES afiliado(id_afiliado) ON DELETE CASCADE,
    fecha_apertura DATE NOT NULL DEFAULT CURRENT_DATE,
    alergias TEXT,
    grupo_sanguineo VARCHAR(5),
    antecedentes TEXT,
    observaciones TEXT
);

CREATE TABLE horario (
    id_horario SERIAL PRIMARY KEY,
    id_medico INTEGER NOT NULL REFERENCES medico(id_medico),
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    CHECK (hora_fin > hora_inicio),
    CHECK (hora_fin - hora_inicio <= INTERVAL '1 hour'),
    UNIQUE (id_medico, fecha, hora_inicio)
);

CREATE TABLE cita (
    id_cita SERIAL PRIMARY KEY,
    id_afiliado INTEGER NOT NULL REFERENCES afiliado(id_afiliado),
    id_servicio SMALLINT NOT NULL REFERENCES servicio(id_servicio),
    id_horario INTEGER NOT NULL UNIQUE REFERENCES horario(id_horario),
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_estado SMALLINT NOT NULL REFERENCES estado(id_estado),
    valor_cobrado NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (valor_cobrado >= 0)
);




-- Datos de catálogo necesarios para usar los formularios.
INSERT INTO tipo_documento (sigla, nombre) VALUES
    ('CN','Certificado de nacido vivo'),
    ('RC','Registro civil de nacimiento'),
    ('TI','Tarjeta de identidad'),
    ('CC','Cédula de ciudadanía'),
    ('CE','Cédula de extranjería'),
    ('PA','Pasaporte'),
    ('CD','Carné diplomático'),
    ('SC','Salvoconducto de permanencia'),
    ('PEP','Permiso especial de permanencia'),
    ('PPT','Permiso por protección temporal');

INSERT INTO estado (nombre, ambito) VALUES
    ('Disponible','horario'),
    ('Ocupado','horario'),
    ('Programada','cita'),
    ('Atendida','cita'),
    ('Cancelada','cita'),
    ('Activo','consultorio'),
    ('Inactivo','consultorio');

INSERT INTO tarifa (nombre, ingreso_min, ingreso_max, porcentaje_cobro) VALUES
    ('Categoría A',0,1000000,0),
    ('Categoría B',1000000.01,2500000,5),
    ('Categoría C',2500000.01,9999999999.99,10);

INSERT INTO especialidad (nombre, descripcion) VALUES
    ('Medicina general','Atención primaria'),
    ('Pediatría','Salud infantil'),
    ('Odontología','Salud oral'),
    ('Cardiología','Sistema cardiovascular'),
    ('Dermatología','Salud de la piel'),
    ('Ginecología y obstetricia','Salud de la mujer'),
    ('Oftalmología','Salud visual'),
    ('Ortopedia','Sistema osteomuscular'),
    ('Medicina interna','Atención integral del adulto'),
    ('Psicología','Salud mental'),
    ('Nutrición','Alimentación y nutrición'),
    ('Enfermería','Cuidados de enfermería');

INSERT INTO servicio (nombre, descripcion, costo_base) VALUES
    ('Medicina general','Consulta médica general',50000),
    ('Control de medicina general','Seguimiento de consulta general',40000),
    ('Consulta preventiva','Valoración preventiva de salud',45000),
    ('Pediatría','Atención médica infantil',70000),
    ('Control de crecimiento y desarrollo','Seguimiento infantil',60000),
    ('Odontología general','Valoración odontológica',60000),
    ('Limpieza dental','Profilaxis y limpieza dental',80000),
    ('Cardiología','Consulta cardiovascular',100000),
    ('Dermatología','Consulta de piel',90000),
    ('Ginecología','Consulta ginecológica',90000),
    ('Oftalmología','Valoración visual',85000),
    ('Ortopedia','Valoración osteomuscular',95000),
    ('Medicina interna','Consulta de medicina interna',90000),
    ('Psicología','Consulta psicológica',80000),
    ('Nutrición','Consulta nutricional',65000),
    ('Enfermería','Procedimientos básicos de enfermería',35000);

-- Los IDs se generan en el orden de inserción anterior en una base vacía.
-- CC es el tipo 4; las especialidades y los servicios se numeran desde 1.
INSERT INTO servicio_especialidad (id_servicio, id_especialidad) VALUES
    (1,1), (2,1), (3,1), (4,2), (5,2), (6,3), (7,3), (8,4),
    (9,5), (10,6), (11,7), (12,8), (13,9), (14,10), (15,11), (16,12);

-- El estado Activo corresponde al ID 6.
INSERT INTO consultorio (nombre, ubicacion, capacidad, id_estado) VALUES
    ('Consultorio 101','Piso 1',3,6),
    ('Consultorio 102','Piso 1',3,6),
    ('Consultorio 201','Piso 2',2,6),
    ('Consultorio 103','Piso 1',2,6),
    ('Consultorio 202','Piso 2',2,6),
    ('Consultorio 203','Piso 2',2,6),
    ('Consultorio 301','Piso 3',2,6);

-- Asocia los consultorios y las especialidades usando sus IDs.
INSERT INTO consultorio_especialidad (id_consultorio, id_especialidad) VALUES
    (1,1), (2,2), (3,3), (5,4), (6,5), (7,6),
    (4,7), (4,8), (4,9), (4,10), (4,11), (4,12);

-- Datos de demostración: un administrador, siete médicos y siete afiliados.
-- La contraseña de todas las cuentas de demostración es 2345.
INSERT INTO usuario (username, password_hash) VALUES
    ('99999999','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('diana.rojas','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('carlos.mejia','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('lucia.torres','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('andres.mora','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('carolina.gil','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('felipe.ruiz','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('natalia.santos','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('ana.martinez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('luis.gomez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('laura.ramirez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('miguel.perez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('sofia.lopez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('juan.hernandez','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6'),
    ('valentina.diaz','$2y$12$XZWRqvXpBqAbC2UOy5lOYuVvx8oG3HEQLZNRzAUSUHr1BXLY5X6');

-- IDs de usuario: administrador 1, médicos 2 a 8 y afiliados 9 a 15.
-- ID del tipo de documento CC: 4.
INSERT INTO medico (id_usuario, id_tipo_doc, num_documento, nombre1, apellido1, telefono, correo, id_especialidad, id_consultorio) VALUES
    (2,4,'90000001','Diana','Rojas','3005550100','diana.rojas@eps.local',1,1),
    (3,4,'90000002','Carlos','Mejía','3005550100','carlos.mejia@eps.local',2,2),
    (4,4,'90000003','Lucía','Torres','3005550100','lucia.torres@eps.local',3,3),
    (5,4,'90000004','Andrés','Mora','3005550100','andres.mora@eps.local',1,1),
    (6,4,'90000005','Carolina','Gil','3005550100','carolina.gil@eps.local',4,5),
    (7,4,'90000006','Felipe','Ruiz','3005550100','felipe.ruiz@eps.local',5,6),
    (8,4,'90000007','Natalia','Santos','3005550100','natalia.santos@eps.local',6,7);

-- Un afiliado individual como ejemplo del INSERT.
-- Las tarifas A, B y C tienen IDs 1, 2 y 3, respectivamente.
INSERT INTO afiliado (id_usuario, id_tipo_doc, num_doc, nombre1, apellido1, fecha_nacimiento, telefono, correo, direccion, ingreso_mensual, id_tarifa, estado_afiliacion)
VALUES (9,4,'10000001','Ana','Martínez',DATE '1995-01-02','3005550001','ana.martinez@correo.local','Villavicencio',800000,1,'Activo');

-- Seis afiliados adicionales completan las 15 cuentas y representan las categorías.
INSERT INTO afiliado (id_usuario, id_tipo_doc, num_doc, nombre1, apellido1, fecha_nacimiento, telefono, correo, direccion, ingreso_mensual, id_tarifa, estado_afiliacion) VALUES
    (10,4,'10000002','Luis','Gómez',DATE '1994-05-12','3005550002','luis.gomez@correo.local','Villavicencio',900000,1,'Pendiente'),
    (11,4,'10000003','Laura','Ramírez',DATE '1992-06-18','3005550003','laura.ramirez@correo.local','Villavicencio',1800000,2,'Activo'),
    (12,4,'10000004','Miguel','Pérez',DATE '1990-07-24','3005550004','miguel.perez@correo.local','Villavicencio',1800000,2,'Pendiente'),
    (13,4,'10000005','Sofía','López',DATE '1996-08-30','3005550005','sofia.lopez@correo.local','Villavicencio',2200000,2,'Pendiente'),
    (14,4,'10000006','Juan','Hernández',DATE '1988-09-14','3005550006','juan.hernandez@correo.local','Villavicencio',3200000,3,'Activo'),
    (15,4,'10000007','Valentina','Díaz',DATE '1998-10-21','3005550007','valentina.diaz@correo.local','Villavicencio',3200000,3,'Pendiente');

-- Un historial de muestra para cada afiliado.
INSERT INTO historial (id_afiliado, alergias, grupo_sanguineo, antecedentes) VALUES
    (1,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (2,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (3,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (4,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (5,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (6,'Sin alergias registradas','O+','Sin antecedentes registrados'),
    (7,'Sin alergias registradas','O+','Sin antecedentes registrados');

-- Estado Disponible tiene ID 1; cada médico recibe un horario inicial.
INSERT INTO horario (id_medico, fecha, hora_inicio, hora_fin, id_estado) VALUES
    (1,CURRENT_DATE + 1,TIME '08:00',TIME '09:00',1),
    (2,CURRENT_DATE + 1,TIME '08:00',TIME '09:00',1),
    (3,CURRENT_DATE + 1,TIME '08:00',TIME '09:00',1),
    (4,CURRENT_DATE + 1,TIME '09:00',TIME '10:00',1),
    (5,CURRENT_DATE + 1,TIME '09:00',TIME '10:00',1),
    (6,CURRENT_DATE + 1,TIME '10:00',TIME '11:00',1),
    (7,CURRENT_DATE + 1,TIME '10:00',TIME '11:00',1);

-- Evita que un afiliado tenga dos citas pendientes que se crucen en el tiempo.
CREATE OR REPLACE FUNCTION evitar_citas_traslapadas_afiliado()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $func$
DECLARE
    estado_programada SMALLINT;
    fecha_nueva DATE;
    inicio_nuevo TIME;
    fin_nuevo TIME;
    cita_en_conflicto INTEGER;
BEGIN
    SELECT id_estado INTO estado_programada
    FROM estado
    WHERE ambito = 'cita' AND nombre = 'Programada';

    IF estado_programada IS NULL THEN
        RAISE EXCEPTION 'No está configurado el estado Programada para las citas.';
    END IF;

    -- Solo se revisan citas que quedan pendientes de atención.
    IF NEW.id_estado <> estado_programada THEN
        RETURN NEW;
    END IF;

    SELECT fecha, hora_inicio, hora_fin
    INTO fecha_nueva, inicio_nuevo, fin_nuevo
    FROM horario
    WHERE id_horario = NEW.id_horario;

    SELECT c.id_cita INTO cita_en_conflicto
    FROM cita c
    JOIN horario h ON h.id_horario = c.id_horario
    WHERE c.id_afiliado = NEW.id_afiliado
      AND c.id_estado = estado_programada
      AND h.fecha = fecha_nueva
      AND inicio_nuevo < h.hora_fin
      AND fin_nuevo > h.hora_inicio
      AND c.id_cita IS DISTINCT FROM NEW.id_cita
    LIMIT 1;

    IF cita_en_conflicto IS NOT NULL THEN
        RAISE EXCEPTION 'Ya tienes una cita pendiente que coincide con ese horario.';
    END IF;

    RETURN NEW;
END;
$func$;

CREATE TRIGGER trg_evitar_citas_traslapadas_afiliado
BEFORE INSERT OR UPDATE OF id_afiliado, id_horario, id_estado ON cita
FOR EACH ROW EXECUTE FUNCTION evitar_citas_traslapadas_afiliado();

-- Retira la disponibilidad de horarios vencidos que nunca se reservaron.
CREATE OR REPLACE FUNCTION limpiar_horarios_vencidos()
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    DELETE FROM horario h
    USING estado e
    WHERE e.id_estado = h.id_estado
      AND e.ambito = 'horario'
      AND e.nombre = 'Disponible'
      AND (h.fecha + h.hora_fin) <= (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')
      AND NOT EXISTS (SELECT 1 FROM cita c WHERE c.id_horario = h.id_horario);
END;
$$;




CREATE OR REPLACE FUNCTION disparador_limpieza_horarios_vencidos()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM limpiar_horarios_vencidos();
    RETURN NEW;
END;
$$;




DROP TRIGGER IF EXISTS limpiar_horarios_vencidos_al_cambiar ON horario;
CREATE TRIGGER limpiar_horarios_vencidos_al_cambiar
AFTER INSERT OR UPDATE ON horario
FOR EACH ROW EXECUTE FUNCTION disparador_limpieza_horarios_vencidos();
GRANT EXECUTE ON FUNCTION limpiar_horarios_vencidos() TO eps_app;


-- Copago automático (máximo $10.000)
CREATE FUNCTION calcular_valor_cita() RETURNS TRIGGER AS $$
DECLARE
    costo NUMERIC(10,2);
    porcentaje NUMERIC(5,2);
BEGIN
    SELECT s.costo_base, t.porcentaje_cobro INTO costo, porcentaje
    FROM servicio s
    JOIN afiliado a ON a.id_afiliado=NEW.id_afiliado
    JOIN tarifa t ON t.id_tarifa=a.id_tarifa
    WHERE s.id_servicio=NEW.id_servicio;
    IF costo IS NULL OR porcentaje IS NULL THEN
        RAISE EXCEPTION 'No se encontró el servicio o la tarifa del afiliado.';
    END IF;
    NEW.valor_cobrado := LEAST(ROUND(costo * porcentaje / 100, 2), 10000.00);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_calcular_valor_cita
BEFORE INSERT OR UPDATE OF id_servicio, id_afiliado ON cita
FOR EACH ROW EXECUTE FUNCTION calcular_valor_cita();




-- Actualiza la disponibilidad del horario al reservar o borrar una cita.
CREATE FUNCTION actualizar_estado_horario() RETURNS TRIGGER AS $$
DECLARE
    horario_actual INTEGER;
    nuevo_estado SMALLINT;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        horario_actual := OLD.id_horario;
        SELECT id_estado INTO nuevo_estado FROM estado
        WHERE ambito='horario'
          AND nombre=CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario=horario_actual)
                          THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado=nuevo_estado WHERE id_horario=horario_actual;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        horario_actual := NEW.id_horario;
        SELECT id_estado INTO nuevo_estado FROM estado
        WHERE ambito='horario'
          AND nombre=CASE WHEN EXISTS (SELECT 1 FROM cita WHERE id_horario=horario_actual)
                          THEN 'Ocupado' ELSE 'Disponible' END;
        UPDATE horario SET id_estado=nuevo_estado WHERE id_horario=horario_actual;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_actualizar_estado_horario
AFTER INSERT OR UPDATE OR DELETE ON cita
FOR EACH ROW EXECUTE FUNCTION actualizar_estado_horario();


-- Cita de ejemplo para probar copago y ocupación del horario.
-- IDs de afiliado, servicio, horario y estado: 1, 1, 1 y 3.
INSERT INTO cita (id_afiliado, id_servicio, id_horario, id_estado)
VALUES (1,1,1,3);

-- Permisos de conexión y grupos PostgreSQL.
DO $$ BEGIN
    EXECUTE format(
        'GRANT CONNECT ON DATABASE %I TO eps_app, eps_lectura, eps_paciente, eps_medico, eps_administrador',
        current_database()
    );
END $$;
GRANT USAGE ON SCHEMA public TO eps_app, eps_lectura, eps_paciente, eps_medico, eps_administrador;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO eps_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO eps_app;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO eps_lectura;
GRANT SELECT ON tipo_documento, estado, tarifa, especialidad, servicio, servicio_especialidad, consultorio,
    consultorio_especialidad, horario, afiliado, medico, cita, historial TO eps_paciente;
GRANT INSERT ON cita TO eps_paciente;
GRANT UPDATE (id_estado) ON horario TO eps_paciente;
GRANT USAGE, SELECT ON SEQUENCE cita_id_cita_seq TO eps_paciente;
GRANT SELECT ON tipo_documento, estado, especialidad, servicio, servicio_especialidad, consultorio,
    consultorio_especialidad, horario, afiliado, medico, cita, historial TO eps_medico;
GRANT UPDATE (id_estado) ON cita TO eps_medico;
GRANT UPDATE (id_estado) ON horario TO eps_medico;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO eps_administrador;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO eps_administrador;

COMMENT ON TABLE usuario IS 'Credenciales de acceso para afiliados, médicos y administrador.';
COMMENT ON TABLE consultorio_especialidad IS 'Relación muchos a muchos entre consultorios y especialidades.';
COMMENT ON TABLE servicio_especialidad IS 'Define las especialidades médicas habilitadas para cada servicio.';

COMMIT;
