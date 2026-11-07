-- Ejecutar como propietario de la base después de importar proyecto.sql.
\set ON_ERROR_STOP on
\if :{?admin_username}
\else
\prompt 'Nombre de usuario administrador: ' admin_username
\endif
\if :{?admin_password}
\else
\prompt 'Contraseña (mínimo 10 caracteres): ' admin_password
\endif
SELECT length(:'admin_password')>=10 AS password_ok \gset
\if :password_ok
\else
\echo 'La contraseña debe tener al menos 10 caracteres.'
\quit 3
\endif
INSERT INTO usuarios(username,password_hash,id_rol)
SELECT lower(trim(:'admin_username')),crypt(:'admin_password',gen_salt('bf')),id_rol FROM roles WHERE codigo='administrador'
ON CONFLICT(username) DO NOTHING;
