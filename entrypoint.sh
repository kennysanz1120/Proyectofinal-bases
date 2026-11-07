#!/bin/sh
set -eu
: "${DATABASE_URL:?Configura DATABASE_URL}"
: "${DB_PASSWORD:?Configura DB_PASSWORD}"
: "${DB_ADMIN_PASSWORD:?Configura DB_ADMIN_PASSWORD}"
: "${APP_ADMIN_PASSWORD:?Configura APP_ADMIN_PASSWORD}"
export PGCONNECT_TIMEOUT=10
until psql "$DATABASE_URL" -Atqc 'SELECT 1' >/dev/null 2>&1; do echo 'Esperando PostgreSQL...'; sleep 3; done
schema=$(psql "$DATABASE_URL" -Atqc "SELECT to_regclass('public.usuarios') IS NOT NULL")
legacy=$(psql "$DATABASE_URL" -Atqc "SELECT to_regclass('public.afiliado') IS NOT NULL")
if [ "$schema" != "t" ]; then
    if [ "$legacy" = "t" ]; then echo 'La base contiene el esquema antiguo. Usa una base vacía y conserva la anterior como respaldo.' >&2; exit 1; fi
    psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -v app_password="$DB_PASSWORD" -v admin_password="$DB_ADMIN_PASSWORD" -f /opt/crear_usuario_app.sql
    psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /opt/proyecto.sql
fi
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -v admin_username="${APP_ADMIN_USERNAME:-admin}" -v admin_password="$APP_ADMIN_PASSWORD" -f /opt/crear_administrador.sql
exec apache2-foreground
