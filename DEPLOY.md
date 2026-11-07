# Despliegue en Render

1. Publica el repositorio en GitHub y crea un Blueprint en Render apuntando a `render.yaml`.
2. El Blueprint crea una base PostgreSQL y el servicio web. Las claves se generan como variables secretas.
3. El contenedor espera a PostgreSQL, crea los roles y esquema en la primera ejecución e inserta la cuenta web inicial `admin`.
4. Abre la URL pública del servicio e inicia sesión con `APP_ADMIN_USERNAME` y `APP_ADMIN_PASSWORD`.
5. Desde administración, crea las cuentas médicas, horarios y aprobaciones de afiliación.

La instalación inicial necesita una base vacía y una cuenta de conexión propietaria con permiso para crear roles. Si Render devuelve `permission denied to create role`, la cuenta usada en `DATABASE_URL` no tiene `CREATEROLE`: el despliegue se detendrá, y la configuración de roles deberá hacerse con una cuenta autorizada. Las extensiones `btree_gist` y `pgcrypto` también deben estar disponibles.

Los usuarios creados directamente con `CREATE ROLE` no son credenciales administradas por Render y no aparecen en su panel. No publiques variables secretas, datos de pacientes ni credenciales.

El contenedor se detiene si detecta la antigua tabla `afiliado`, para evitar sobrescribir la base anterior. Este proyecto no incluye una migración automática; respalda los datos y crea una base aparte para esta versión.
