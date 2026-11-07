# 🏥 EPS Villavicencio
### Portal académico de citas médicas y afiliaciones

Aplicación web académica con PHP y PostgreSQL. Los datos son de demostración; no registres información clínica real.

## Funciones por perfil

| Perfil | Permisos principales |
|---|---|
| Paciente | Consulta su perfil, afiliación, historia y citas; agenda una cita disponible si está activo. |
| Médico | Consulta su agenda y los datos de pacientes que atiende; registra el resultado de su cita. |
| Administrador | Gestiona afiliaciones, perfiles médicos, horarios y registros de la EPS. |

La afiliación comienza en estado `Pendiente`. La página no procesa pagos; administración la activa cuando confirma el pago externo.

## Requisitos

- Linux, PHP 8.2 con `pdo_pgsql` y PostgreSQL 14 o superior.
- Extensiones PostgreSQL `btree_gist` y `pgcrypto`.

## Instalación en una base vacía

```bash
sudo -u postgres createdb eps_villavicencio
sudo -u postgres psql -d postgres -f crear_usuario_app.sql
sudo -u postgres psql -d eps_villavicencio -v ON_ERROR_STOP=1 -f proyecto.sql
sudo -u postgres psql -d eps_villavicencio -f crear_administrador.sql
```

Los dos primeros roles de conexión que solicita `crear_usuario_app.sql` son `eps_app` y `eps_admin`. No son superusuarios. `crear_administrador.sql` solicita el usuario y la contraseña iniciales del portal.

Configura las variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ADMIN_USER` y `DB_ADMIN_PASSWORD`. También se puede usar `DATABASE_URL` para host, puerto, base y SSL. No guardes contraseñas en GitHub.

Inicia desde esta carpeta:

```bash
php -S 127.0.0.1:8000
```

Abre <http://127.0.0.1:8000>.

## Seguridad de base de datos

- `roles` y `usuarios` separan el inicio de sesión de los perfiles personales `pacientes` y `medicos`.
- RLS restringe las filas visibles. Pacientes y médicos no reciben permisos DML directos sobre tablas.
- Reservar cita, guardar resultado y aprobar afiliación son funciones SQL con validaciones propias.
- Las contraseñas se almacenan con `pgcrypto`; PostgreSQL conserva el hash de un token aleatorio para validar la sesión.
- Las conexiones de PostgreSQL nunca llegan al navegador; deben permanecer privadas y configuradas como secretos del servicio.

## Archivos

| Archivo | Uso |
|---|---|
| `proyecto.sql` | Tablas, restricciones, funciones, disparadores, RLS y catálogos. |
| `crear_usuario_app.sql` | Roles funcionales de PostgreSQL y usuarios de conexión. |
| `crear_administrador.sql` | Cuenta administrativa inicial del portal. |
| `index.php`, `Database.php`, `config.php` | Interfaz, acceso y configuración PDO. |
| `assets/app.css` | Diseño institucional adaptable. |
| `modelo_entidad_relacion.mmd` | Diagrama ER en Mermaid. |
| `Informe_Proyecto.docx` | Informe, objetivos, justificación y diccionario de datos. |
| `Dockerfile`, `entrypoint.sh`, `render.yaml`, `DEPLOY.md` | Despliegue con contenedor en Render. |

## Aviso de instalación

`proyecto.sql` se ejecuta una sola vez en una base vacía; no convierte los datos del esquema anterior. Si ya tienes tablas existentes, respáldalas y crea una base nueva para esta versión.

## Uso de índices

PostgreSQL crea índices para claves primarias y restricciones `UNIQUE`. Antes de agregar más, evalúa las consultas con `EXPLAIN ANALYZE`.
