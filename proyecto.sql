-- EPS Villavicencio: esquema normalizado, funciones de negocio y RBAC/RLS.
-- Ejecutar en una base de datos vacía después de crear roles con crear_usuario_app.sql.
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE roles (
 id_rol SMALLSERIAL PRIMARY KEY,
 codigo VARCHAR(24) NOT NULL UNIQUE CHECK (codigo IN ('paciente','medico','administrador')),
 nombre VARCHAR(60) NOT NULL
);
INSERT INTO roles(codigo,nombre) VALUES ('paciente','Paciente'),('medico','Médico'),('administrador','Administrador');
CREATE TABLE usuarios (
 id_usuario BIGSERIAL PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE,
 password_hash TEXT NOT NULL, id_rol SMALLINT NOT NULL REFERENCES roles,
 activo BOOLEAN NOT NULL DEFAULT TRUE, creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
 CHECK (length(username) BETWEEN 4 AND 80)
);
CREATE TABLE sesiones_app (
 token_hash CHAR(64) PRIMARY KEY, id_usuario BIGINT NOT NULL REFERENCES usuarios ON DELETE CASCADE,
 expira_en TIMESTAMPTZ NOT NULL
);
CREATE TABLE tipo_documento(id_tipo_doc SMALLSERIAL PRIMARY KEY,sigla VARCHAR(10) NOT NULL UNIQUE,nombre VARCHAR(100) NOT NULL);
CREATE TABLE estado(id_estado SMALLSERIAL PRIMARY KEY,nombre VARCHAR(40) NOT NULL,ambito VARCHAR(24) NOT NULL CHECK(ambito IN ('consultorio','horario','cita')),UNIQUE(ambito,nombre));
CREATE TABLE tarifa(id_tarifa SMALLSERIAL PRIMARY KEY,nombre VARCHAR(60) NOT NULL UNIQUE,ingreso_min NUMERIC(12,2) NOT NULL CHECK(ingreso_min>=0),ingreso_max NUMERIC(12,2) NOT NULL CHECK(ingreso_max>=ingreso_min),porcentaje_cobro NUMERIC(5,2) NOT NULL CHECK(porcentaje_cobro BETWEEN 0 AND 100));
CREATE TABLE servicio(id_servicio SMALLSERIAL PRIMARY KEY,nombre VARCHAR(100) NOT NULL UNIQUE,descripcion TEXT,costo_base NUMERIC(10,2) NOT NULL CHECK(costo_base>=0));
CREATE TABLE especialidad(id_especialidad SMALLSERIAL PRIMARY KEY,nombre VARCHAR(100) NOT NULL UNIQUE,descripcion TEXT);
CREATE TABLE consultorio(id_consultorio BIGSERIAL PRIMARY KEY,nombre VARCHAR(50) NOT NULL UNIQUE,ubicacion VARCHAR(100) NOT NULL,capacidad INT NOT NULL CHECK(capacidad>=0),id_estado SMALLINT NOT NULL REFERENCES estado);
CREATE TABLE consultorio_especialidad(id_consultorio BIGINT NOT NULL REFERENCES consultorio ON DELETE CASCADE,id_especialidad SMALLINT NOT NULL REFERENCES especialidad ON DELETE CASCADE,PRIMARY KEY(id_consultorio,id_especialidad));
CREATE TABLE pacientes(
 id_paciente BIGSERIAL PRIMARY KEY,id_usuario BIGINT NOT NULL UNIQUE REFERENCES usuarios ON DELETE CASCADE,
 id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento,id_tarifa SMALLINT REFERENCES tarifa,
 num_doc VARCHAR(20) NOT NULL,nombre1 VARCHAR(50) NOT NULL,nombre2 VARCHAR(50),apellido1 VARCHAR(50) NOT NULL,apellido2 VARCHAR(50),
 sexo VARCHAR(15),fecha_nacimiento DATE NOT NULL CHECK(fecha_nacimiento<=CURRENT_DATE),telefono VARCHAR(20),correo VARCHAR(100),direccion VARCHAR(150),
 ingreso_mensual NUMERIC(12,2) NOT NULL CHECK(ingreso_mensual>=0),
 estado_afiliacion VARCHAR(12) NOT NULL DEFAULT 'Pendiente' CHECK(estado_afiliacion IN ('Pendiente','Activo','Inactivo')),
 UNIQUE(id_tipo_doc,num_doc)
);
CREATE TABLE medicos(
 id_medico BIGSERIAL PRIMARY KEY,id_usuario BIGINT NOT NULL UNIQUE REFERENCES usuarios ON DELETE CASCADE,
 id_tipo_doc SMALLINT NOT NULL REFERENCES tipo_documento,num_doc VARCHAR(20) NOT NULL,
 nombre1 VARCHAR(50) NOT NULL,nombre2 VARCHAR(50),apellido1 VARCHAR(50) NOT NULL,apellido2 VARCHAR(50),
 correo VARCHAR(100),telefono VARCHAR(20),id_especialidad SMALLINT NOT NULL REFERENCES especialidad,
 id_consultorio BIGINT REFERENCES consultorio ON DELETE SET NULL,UNIQUE(id_tipo_doc,num_doc)
);
CREATE TABLE historial(
 id_historial BIGSERIAL PRIMARY KEY,id_paciente BIGINT NOT NULL UNIQUE REFERENCES pacientes ON DELETE CASCADE,
 fecha_apertura DATE NOT NULL DEFAULT CURRENT_DATE,alergias TEXT,grupo_sanguineo VARCHAR(5),antecedentes TEXT,observaciones TEXT
);
CREATE TABLE horario(
 id_horario BIGSERIAL PRIMARY KEY,id_medico BIGINT NOT NULL REFERENCES medicos ON DELETE RESTRICT,
 fecha DATE NOT NULL,hora_inicio TIME NOT NULL,hora_fin TIME NOT NULL CHECK(hora_fin>hora_inicio),
 id_estado SMALLINT NOT NULL REFERENCES estado,
 EXCLUDE USING gist(id_medico WITH =,tsrange((fecha+hora_inicio)::timestamp,(fecha+hora_fin)::timestamp,'[)') WITH &&)
);
CREATE TABLE cita(
 id_cita BIGSERIAL PRIMARY KEY,id_paciente BIGINT NOT NULL REFERENCES pacientes ON DELETE RESTRICT,
 id_servicio SMALLINT NOT NULL REFERENCES servicio,id_horario BIGINT NOT NULL UNIQUE REFERENCES horario ON DELETE RESTRICT,
 fecha_solicitud TIMESTAMPTZ NOT NULL DEFAULT now(),id_estado SMALLINT NOT NULL REFERENCES estado,
 valor_cobrado NUMERIC(10,2) NOT NULL CHECK(valor_cobrado>=0)
);
CREATE TABLE resultado_consulta(
 id_resultado BIGSERIAL PRIMARY KEY,id_cita BIGINT NOT NULL UNIQUE REFERENCES cita ON DELETE CASCADE,
 id_medico BIGINT NOT NULL REFERENCES medicos,resultado TEXT NOT NULL CHECK(length(trim(resultado))>=3),actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE FUNCTION fn_validar_perfil() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_rol TEXT;
BEGIN
 SELECT r.codigo INTO v_rol FROM usuarios u JOIN roles r USING(id_rol) WHERE u.id_usuario=NEW.id_usuario;
 IF v_rol IS DISTINCT FROM CASE WHEN TG_TABLE_NAME='pacientes' THEN 'paciente' ELSE 'medico' END THEN
   RAISE EXCEPTION 'El rol de la cuenta no corresponde con el perfil personal.';
 END IF;
 IF TG_TABLE_NAME='pacientes' AND EXISTS(SELECT 1 FROM medicos WHERE id_usuario=NEW.id_usuario) THEN RAISE EXCEPTION 'La cuenta ya tiene perfil médico.'; END IF;
 IF TG_TABLE_NAME='medicos' AND EXISTS(SELECT 1 FROM pacientes WHERE id_usuario=NEW.id_usuario) THEN RAISE EXCEPTION 'La cuenta ya tiene perfil de paciente.'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER trg_rol_paciente BEFORE INSERT OR UPDATE OF id_usuario ON pacientes FOR EACH ROW EXECUTE FUNCTION fn_validar_perfil();
CREATE TRIGGER trg_rol_medico BEFORE INSERT OR UPDATE OF id_usuario ON medicos FOR EACH ROW EXECUTE FUNCTION fn_validar_perfil();

INSERT INTO tipo_documento(sigla,nombre) VALUES ('CC','Cédula de ciudadanía'),('TI','Tarjeta de identidad'),('RC','Registro civil'),('CE','Cédula de extranjería'),('PA','Pasaporte');
INSERT INTO estado(nombre,ambito) VALUES ('Disponible','consultorio'),('Disponible','horario'),('Ocupado','horario'),('Programada','cita'),('Atendida','cita'),('Cancelada','cita');
INSERT INTO tarifa(nombre,ingreso_min,ingreso_max,porcentaje_cobro) VALUES ('Categoría A',0,1500000,0),('Categoría B',1500000.01,3000000,20),('Categoría C',3000000.01,50000000,40),('Régimen especial',0,50000000,0);
INSERT INTO servicio(nombre,descripcion,costo_base) VALUES ('Medicina general','Consulta médica general',50000),('Pediatría','Atención infantil',70000),('Odontología','Valoración odontológica',60000);
INSERT INTO especialidad(nombre,descripcion) VALUES ('Medicina general','Atención médica primaria'),('Pediatría','Atención infantil'),('Odontología','Salud oral');
INSERT INTO consultorio(nombre,ubicacion,capacidad,id_estado) SELECT n,u,c,id_estado FROM (VALUES ('Consultorio 101','Piso 1',3),('Consultorio 102','Piso 1',3),('Consultorio 201','Piso 2',2)) x(n,u,c) CROSS JOIN estado WHERE ambito='consultorio' AND estado.nombre='Disponible';
INSERT INTO consultorio_especialidad SELECT c.id_consultorio,e.id_especialidad FROM consultorio c CROSS JOIN especialidad e WHERE (c.nombre='Consultorio 101' AND e.nombre='Medicina general') OR (c.nombre='Consultorio 102' AND e.nombre='Pediatría') OR (c.nombre='Consultorio 201' AND e.nombre='Odontología');

-- Sesión web aleatoria: PostgreSQL conserva únicamente el hash del token.
CREATE FUNCTION app_user_id() RETURNS BIGINT LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$
 SELECT id_usuario FROM public.sesiones_app WHERE token_hash=encode(digest(current_setting('app.session_token',true),'sha256'),'hex') AND expira_en>now()
$$;
CREATE FUNCTION app_rol() RETURNS TEXT LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$
 SELECT r.codigo FROM public.usuarios u JOIN public.roles r USING(id_rol) WHERE u.id_usuario=app_user_id()
$$;
CREATE FUNCTION app_paciente_id() RETURNS BIGINT LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$ SELECT id_paciente FROM public.pacientes WHERE id_usuario=app_user_id() $$;
CREATE FUNCTION app_medico_id() RETURNS BIGINT LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$ SELECT id_medico FROM public.medicos WHERE id_usuario=app_user_id() $$;
CREATE FUNCTION medico_atiende_paciente(p_id BIGINT) RETURNS BOOLEAN LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$
 SELECT EXISTS(SELECT 1 FROM public.cita c JOIN public.horario h USING(id_horario) WHERE c.id_paciente=p_id AND h.id_medico=app_medico_id())
$$;
CREATE FUNCTION autenticar_usuario(p_username TEXT,p_password TEXT) RETURNS TABLE(id_usuario BIGINT,rol TEXT,nombre TEXT,session_token TEXT)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$
DECLARE v_id BIGINT; v_rol TEXT; v_nombre TEXT; v_token TEXT;
BEGIN
 SELECT u.id_usuario,r.codigo,COALESCE(p.nombre1||' '||p.apellido1,m.nombre1||' '||m.apellido1,u.username) INTO v_id,v_rol,v_nombre
 FROM usuarios u JOIN roles r USING(id_rol) LEFT JOIN pacientes p USING(id_usuario) LEFT JOIN medicos m USING(id_usuario)
 WHERE u.username=lower(trim(p_username)) AND u.activo AND u.password_hash=crypt(p_password,u.password_hash);
 IF v_id IS NULL THEN RETURN; END IF;
 DELETE FROM sesiones_app WHERE expira_en<=now(); v_token:=encode(gen_random_bytes(32),'hex');
 INSERT INTO sesiones_app(token_hash,id_usuario,expira_en) VALUES(encode(digest(v_token,'sha256'),'hex'),v_id,now()+interval '8 hours');
 RETURN QUERY SELECT v_id,v_rol,v_nombre,v_token;
END $$;
CREATE FUNCTION cerrar_sesion(p_token TEXT) RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp SET row_security=off AS $$
BEGIN DELETE FROM sesiones_app WHERE token_hash=encode(digest(p_token,'sha256'),'hex'); END $$;
CREATE FUNCTION registrar_paciente(p_username TEXT,p_password TEXT,p_tipo SMALLINT,p_doc TEXT,p_nombre1 TEXT,p_nombre2 TEXT,p_apellido1 TEXT,p_apellido2 TEXT,p_sexo TEXT,p_nacimiento DATE,p_telefono TEXT,p_correo TEXT,p_direccion TEXT,p_ingreso NUMERIC) RETURNS BIGINT
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE v_user BIGINT; v_patient BIGINT; v_role SMALLINT;
BEGIN
 IF length(trim(p_username))<4 OR length(p_password)<10 OR p_ingreso<0 THEN RAISE EXCEPTION 'Verifica usuario, contraseña e ingreso declarado.'; END IF;
 SELECT id_rol INTO v_role FROM roles WHERE codigo='paciente';
 INSERT INTO usuarios(username,password_hash,id_rol) VALUES(lower(trim(p_username)),crypt(p_password,gen_salt('bf')),v_role) RETURNING id_usuario INTO v_user;
 INSERT INTO pacientes(id_usuario,id_tipo_doc,num_doc,nombre1,nombre2,apellido1,apellido2,sexo,fecha_nacimiento,telefono,correo,direccion,ingreso_mensual)
 VALUES(v_user,p_tipo,trim(p_doc),trim(p_nombre1),NULLIF(trim(p_nombre2),''),trim(p_apellido1),NULLIF(trim(p_apellido2),''),NULLIF(trim(p_sexo),''),p_nacimiento,NULLIF(trim(p_telefono),''),NULLIF(lower(trim(p_correo)),''),NULLIF(trim(p_direccion),''),p_ingreso) RETURNING id_paciente INTO v_patient;
 INSERT INTO historial(id_paciente) VALUES(v_patient); RETURN v_patient;
END $$;
CREATE FUNCTION reservar_cita(p_horario BIGINT,p_servicio SMALLINT) RETURNS BIGINT LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE v_paciente BIGINT; v_tarifa SMALLINT; v_percent NUMERIC; v_cost NUMERIC; v_state SMALLINT; v_id BIGINT;
BEGIN
 IF app_rol()<>'paciente' THEN RAISE EXCEPTION 'Solo un paciente puede reservar.'; END IF;
 v_paciente:=app_paciente_id();
 SELECT id_tarifa INTO v_tarifa FROM pacientes WHERE id_paciente=v_paciente AND estado_afiliacion='Activo' FOR UPDATE;
 IF v_tarifa IS NULL THEN RAISE EXCEPTION 'La afiliación debe estar activa para reservar.'; END IF;
 SELECT porcentaje_cobro INTO v_percent FROM tarifa WHERE id_tarifa=v_tarifa;
 SELECT costo_base INTO v_cost FROM servicio WHERE id_servicio=p_servicio;
 SELECT id_estado INTO v_state FROM estado WHERE ambito='cita' AND nombre='Programada';
 INSERT INTO cita(id_paciente,id_servicio,id_horario,id_estado,valor_cobrado)
 SELECT v_paciente,p_servicio,h.id_horario,v_state,round(v_cost*v_percent/100,2) FROM horario h JOIN estado e USING(id_estado)
 WHERE h.id_horario=p_horario AND e.ambito='horario' AND e.nombre='Disponible' AND (h.fecha+h.hora_inicio)>(now() AT TIME ZONE 'America/Bogota') RETURNING id_cita INTO v_id;
 IF v_id IS NULL THEN RAISE EXCEPTION 'Horario no disponible o servicio inválido.'; END IF; RETURN v_id;
END $$;
CREATE FUNCTION guardar_resultado_cita(p_cita BIGINT,p_resultado TEXT) RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE v_medico BIGINT; v_state TEXT; v_inicio TIMESTAMP;
BEGIN
 IF app_rol()<>'medico' THEN RAISE EXCEPTION 'Solo un médico puede guardar resultados.'; END IF;
 v_medico:=app_medico_id();
 SELECT e.nombre,h.fecha+h.hora_inicio INTO v_state,v_inicio FROM cita c JOIN horario h USING(id_horario) JOIN estado e USING(id_estado) WHERE c.id_cita=p_cita AND h.id_medico=v_medico FOR UPDATE OF c;
 IF v_state IS NULL OR v_state NOT IN ('Programada','Atendida') THEN RAISE EXCEPTION 'La cita no pertenece a la agenda de este médico o no está vigente.'; END IF;
 IF v_inicio>(now() AT TIME ZONE 'America/Bogota') THEN RAISE EXCEPTION 'Solo se registra el resultado desde la hora de la cita.'; END IF;
 IF length(trim(p_resultado))<3 THEN RAISE EXCEPTION 'Escribe un resultado de atención.'; END IF;
 INSERT INTO resultado_consulta(id_cita,id_medico,resultado) VALUES(p_cita,v_medico,trim(p_resultado)) ON CONFLICT(id_cita) DO UPDATE SET resultado=EXCLUDED.resultado,actualizado_en=now() WHERE resultado_consulta.id_medico=v_medico;
 UPDATE cita SET id_estado=(SELECT id_estado FROM estado WHERE ambito='cita' AND nombre='Atendida') WHERE id_cita=p_cita;
END $$;
CREATE FUNCTION aprobar_afiliacion(p_id BIGINT,p_estado TEXT,p_tarifa SMALLINT) RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF app_rol()<>'administrador' THEN RAISE EXCEPTION 'Solo administración puede cambiar afiliaciones.'; END IF;
 IF p_estado NOT IN ('Pendiente','Activo','Inactivo') OR (p_estado='Activo' AND p_tarifa IS NULL) THEN RAISE EXCEPTION 'Estado o tarifa inválidos.'; END IF;
 UPDATE pacientes SET estado_afiliacion=p_estado,id_tarifa=CASE WHEN p_estado='Activo' THEN p_tarifa ELSE id_tarifa END WHERE id_paciente=p_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'Paciente no encontrado.'; END IF;
END $$;

-- El trigger calcula el cobro desde la tarifa y mantiene el estado del horario.
CREATE FUNCTION fn_costo_cita() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN SELECT round(s.costo_base*t.porcentaje_cobro/100,2) INTO NEW.valor_cobrado FROM servicio s JOIN pacientes p ON p.id_paciente=NEW.id_paciente JOIN tarifa t ON t.id_tarifa=p.id_tarifa WHERE s.id_servicio=NEW.id_servicio; RETURN NEW; END $$;
CREATE TRIGGER trg_costo_cita BEFORE INSERT OR UPDATE OF id_servicio,id_paciente ON cita FOR EACH ROW EXECUTE FUNCTION fn_costo_cita();
CREATE FUNCTION fn_estado_horario() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_id BIGINT; v_state SMALLINT;
BEGIN
 IF TG_OP<>'INSERT' THEN
  v_id:=OLD.id_horario;
  SELECT id_estado INTO v_state FROM estado WHERE ambito='horario' AND nombre=CASE WHEN EXISTS(SELECT 1 FROM cita WHERE id_horario=v_id) THEN 'Ocupado' ELSE 'Disponible' END;
  UPDATE horario SET id_estado=v_state WHERE id_horario=v_id;
 END IF;
 IF TG_OP<>'DELETE' THEN
  v_id:=NEW.id_horario;
  SELECT id_estado INTO v_state FROM estado WHERE ambito='horario' AND nombre=CASE WHEN EXISTS(SELECT 1 FROM cita WHERE id_horario=v_id) THEN 'Ocupado' ELSE 'Disponible' END;
  UPDATE horario SET id_estado=v_state WHERE id_horario=v_id;
 END IF;
 RETURN NULL;
END $$;
CREATE TRIGGER trg_estado_horario AFTER INSERT OR UPDATE OR DELETE ON cita FOR EACH ROW EXECUTE FUNCTION fn_estado_horario();
CREATE VIEW horarios_disponibles AS
 SELECT h.id_horario,h.fecha,h.hora_inicio,h.hora_fin,m.nombre1||' '||m.apellido1 AS medico,e.nombre AS especialidad
 FROM horario h JOIN estado st USING(id_estado) JOIN medicos m USING(id_medico) JOIN especialidad e USING(id_especialidad)
 WHERE st.ambito='horario' AND st.nombre='Disponible' AND (h.fecha+h.hora_inicio)>(now() AT TIME ZONE 'America/Bogota');

ALTER TABLE usuarios ENABLE ROW LEVEL SECURITY;
ALTER TABLE pacientes ENABLE ROW LEVEL SECURITY;
ALTER TABLE medicos ENABLE ROW LEVEL SECURITY;
ALTER TABLE historial ENABLE ROW LEVEL SECURITY;
ALTER TABLE horario ENABLE ROW LEVEL SECURITY;
ALTER TABLE cita ENABLE ROW LEVEL SECURITY;
ALTER TABLE resultado_consulta ENABLE ROW LEVEL SECURITY;
CREATE POLICY usuario_lectura ON usuarios FOR SELECT USING(current_user='rbac_administrador' OR id_usuario=app_user_id());
CREATE POLICY usuario_admin ON usuarios FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY paciente_lectura ON pacientes FOR SELECT USING(current_user='rbac_administrador' OR id_usuario=app_user_id() OR (current_user='rbac_medico' AND medico_atiende_paciente(id_paciente)));
CREATE POLICY paciente_admin ON pacientes FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY medico_lectura ON medicos FOR SELECT USING(current_user='rbac_administrador' OR id_usuario=app_user_id());
CREATE POLICY medico_admin ON medicos FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY historial_lectura ON historial FOR SELECT USING(current_user='rbac_administrador' OR id_paciente=app_paciente_id() OR (current_user='rbac_medico' AND medico_atiende_paciente(id_paciente)));
CREATE POLICY historial_admin ON historial FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY horario_lectura ON horario FOR SELECT USING(current_user='rbac_administrador' OR id_medico=app_medico_id() OR (current_user='rbac_paciente' AND id_estado=(SELECT id_estado FROM estado WHERE ambito='horario' AND nombre='Disponible') AND (fecha+hora_inicio)>(now() AT TIME ZONE 'America/Bogota')));
CREATE POLICY horario_admin ON horario FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY cita_lectura ON cita FOR SELECT USING(current_user='rbac_administrador' OR id_paciente=app_paciente_id() OR (current_user='rbac_medico' AND EXISTS(SELECT 1 FROM horario h WHERE h.id_horario=cita.id_horario AND h.id_medico=app_medico_id())));
CREATE POLICY cita_admin ON cita FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);
CREATE POLICY resultado_lectura ON resultado_consulta FOR SELECT USING(current_user='rbac_administrador' OR id_medico=app_medico_id() OR EXISTS(SELECT 1 FROM cita c WHERE c.id_cita=resultado_consulta.id_cita AND c.id_paciente=app_paciente_id()));
CREATE POLICY resultado_admin ON resultado_consulta FOR ALL TO rbac_administrador USING(true) WITH CHECK(true);

GRANT USAGE ON SCHEMA public TO eps_app,eps_admin,rbac_paciente,rbac_medico,rbac_administrador;
GRANT SELECT ON tipo_documento,estado,tarifa,servicio,especialidad,consultorio_especialidad TO eps_app,rbac_paciente,rbac_medico,rbac_administrador;
GRANT SELECT ON pacientes,medicos,historial,horario,cita,resultado_consulta TO rbac_paciente,rbac_medico;
GRANT SELECT ON horarios_disponibles TO rbac_paciente;
GRANT SELECT ON roles,usuarios,pacientes,medicos,historial,horario,cita,resultado_consulta,consultorio,consultorio_especialidad,servicio,tarifa,especialidad,estado,tipo_documento TO rbac_administrador;
GRANT INSERT,UPDATE,DELETE ON roles,usuarios,pacientes,medicos,historial,horario,cita,resultado_consulta,consultorio,consultorio_especialidad,servicio,tarifa,especialidad,estado,tipo_documento TO rbac_administrador;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO rbac_administrador;
GRANT EXECUTE ON FUNCTION app_user_id(),app_rol(),app_paciente_id(),app_medico_id(),medico_atiende_paciente(BIGINT) TO eps_app,rbac_paciente,rbac_medico,rbac_administrador;
REVOKE ALL ON FUNCTION app_user_id(),app_rol(),app_paciente_id(),app_medico_id(),medico_atiende_paciente(BIGINT) FROM PUBLIC;
REVOKE ALL ON FUNCTION autenticar_usuario(TEXT,TEXT),cerrar_sesion(TEXT),registrar_paciente(TEXT,TEXT,SMALLINT,TEXT,TEXT,TEXT,TEXT,TEXT,TEXT,DATE,TEXT,TEXT,TEXT,NUMERIC),reservar_cita(BIGINT,SMALLINT),guardar_resultado_cita(BIGINT,TEXT),aprobar_afiliacion(BIGINT,TEXT,SMALLINT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION autenticar_usuario(TEXT,TEXT),cerrar_sesion(TEXT),registrar_paciente(TEXT,TEXT,SMALLINT,TEXT,TEXT,TEXT,TEXT,TEXT,TEXT,DATE,TEXT,TEXT,TEXT,NUMERIC) TO eps_app;
GRANT EXECUTE ON FUNCTION reservar_cita(BIGINT,SMALLINT) TO rbac_paciente;
GRANT EXECUTE ON FUNCTION guardar_resultado_cita(BIGINT,TEXT) TO rbac_medico;
GRANT EXECUTE ON FUNCTION aprobar_afiliacion(BIGINT,TEXT,SMALLINT) TO rbac_administrador;
GRANT rbac_paciente,rbac_medico TO eps_app;
GRANT rbac_administrador TO eps_admin;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

COMMENT ON TABLE usuarios IS 'Credenciales y rol funcional; nunca almacena contraseñas en texto plano.';
COMMENT ON TABLE pacientes IS 'Datos personales y estado de afiliación.';
COMMENT ON COLUMN pacientes.estado_afiliacion IS 'Pendiente hasta que administración verifique el pago externo.';
COMMENT ON TABLE resultado_consulta IS 'Resultado de la cita, registrable por el médico asignado.';
