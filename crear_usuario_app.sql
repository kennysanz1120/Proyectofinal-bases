-- Ejecutar como cuenta PostgreSQL con CREATEROLE, antes de proyecto.sql.
\set ON_ERROR_STOP on
SELECT format('CREATE ROLE %I NOLOGIN NOINHERIT',x) FROM (VALUES('rbac_paciente'),('rbac_medico'),('rbac_administrador')) r(x) WHERE NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=x) \gexec
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT',x) FROM (VALUES('eps_app'),('eps_admin')) r(x) WHERE NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=x) \gexec
\if :{?app_password}
ALTER ROLE eps_app PASSWORD :'app_password';
\else
\password eps_app
\endif
\if :{?admin_password}
ALTER ROLE eps_admin PASSWORD :'admin_password';
\else
\password eps_admin
\endif
GRANT rbac_paciente,rbac_medico TO eps_app;
GRANT rbac_administrador TO eps_admin;
