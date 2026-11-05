# 🏥 EPS Villavicencio

### Sistema de gestión y reserva de citas médicas

Aplicación web académica para gestionar afiliados, profesionales de la salud, horarios y citas médicas. Está desarrollada con **PHP** y **PostgreSQL**, sin frameworks.

> 📚 Proyecto académico de Bases de Datos. Los datos incluidos son de demostración y no corresponden a pacientes reales.

---

## ✨ Características

- 📊 Consulta de un resumen de afiliados, citas y horarios.
- 🧑‍⚕️ Registro, consulta y actualización de afiliados.
- 🏥 Registro y actualización de médicos, consultorios y horarios.
- 📅 Reserva de citas según el médico, la fecha y la hora disponibles.
- 📝 Consulta de citas y registro de atención.
- ❌ Cancelación de citas con liberación del horario reservado.

---

## 🧰 Tecnologías y requisitos

- 🐧 Linux
- 🐘 PHP 7.4 o superior
- 🔌 Extensiones PHP: `pdo` y `pdo_pgsql`
- 🗄️ PostgreSQL
- ⚙️ Permiso para habilitar la extensión `btree_gist`, requerida por el archivo SQL

---

## 🚀 Instalación local

### 1️⃣ Crear la base de datos

```bash
sudo -u postgres createdb eps_villavicencio
```

### 2️⃣ Importar la estructura y los datos de demostración

Ejecuta este paso **una sola vez** sobre la base de datos vacía:

```bash
sudo -u postgres psql -d eps_villavicencio -f proyecto.sql
```

El archivo crea las tablas, relaciones, restricciones, disparadores y datos de demostración.

### 3️⃣ Crear el usuario de la aplicación

```bash
sudo -u postgres psql -d postgres -f crear_usuario_app.sql
```

El script solicitará una contraseña para `eps_app`. Este usuario tiene permisos de lectura y escritura sobre los objetos del proyecto; no es superusuario.

### 4️⃣ Configurar las variables de entorno

Configura la conexión con las variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`, o utiliza `DATABASE_URL`.

Por defecto, la aplicación se conecta con el usuario `eps_app`. La variable `DB_PASSWORD` debe coincidir con la contraseña definida en el paso anterior.

La aplicación también requiere datos para identificar al administrador y proteger el acceso administrativo. Define `APP_ADMIN_TIPO_DOC`, `APP_ADMIN_DOCUMENTO`, `APP_ADMIN_NOMBRE` y `APP_ACCESS_PASSWORD`.

En Bash, puedes cargarlas de forma interactiva antes de iniciar PHP:

```bash
export DB_USER=eps_app

read -s -p "Contraseña de PostgreSQL: " DB_PASSWORD
export DB_PASSWORD
echo

read -s -p "Contraseña administrativa: " APP_ACCESS_PASSWORD
export APP_ACCESS_PASSWORD
echo

export APP_ADMIN_TIPO_DOC=CC

read -r -p "Documento del administrador: " APP_ADMIN_DOCUMENTO
export APP_ADMIN_DOCUMENTO

read -r -p "Nombre del administrador: " APP_ADMIN_NOMBRE
export APP_ADMIN_NOMBRE
```

> 🔐 No publiques contraseñas, datos personales ni direcciones privadas de conexión en GitHub. En Render, configura estas variables desde el panel del servicio.

### 5️⃣ Iniciar la aplicación

Desde la carpeta del proyecto, ejecuta:

```bash
php -S 127.0.0.1:8000
```

Abre esta dirección en el navegador:

👉 [http://127.0.0.1:8000](http://127.0.0.1:8000)

---

## 🔐 Acceso al sistema

La página de inicio presenta un formulario de ingreso para afiliados.

El acceso administrativo se habilita cuando coinciden los datos configurados en `APP_ADMIN_TIPO_DOC`, `APP_ADMIN_DOCUMENTO` y `APP_ADMIN_NOMBRE`, y se ingresa la contraseña definida en `APP_ACCESS_PASSWORD`.

---

## ✅ Validaciones y reglas de negocio

- 🪪 **CC, TI y RC:** solo dígitos, hasta 10 caracteres.
- 🛂 **CE y PA:** letras y dígitos, hasta 20 caracteres.
- 👤 Los nombres no admiten números.
- 💰 Los ingresos y la capacidad no pueden ser negativos.
- 🧾 El valor de una cita se calcula con el costo del servicio y la tarifa del afiliado.
- ⚡ Los disparadores y las reglas de integridad se encuentran en `proyecto.sql`.

---

## 🗂️ Estructura del proyecto

| Archivo | Descripción |
|---|---|
| `proyecto.sql` | Crea las tablas, relaciones, reglas, disparadores y datos de demostración. |
| `crear_usuario_app.sql` | Crea el usuario PostgreSQL de la aplicación y configura sus permisos. |
| `Database.php` | Gestiona la conexión PDO con PostgreSQL. |
| `index.php` | Presenta el inicio y permite acceder a los módulos del sistema. |

---

## 📝 Consideraciones de la base de datos

`Database.php` implementa el patrón Singleton para reutilizar una única instancia de PDO durante cada petición PHP.

Las claves primarias y las restricciones `UNIQUE` ya crean índices automáticamente en PostgreSQL. Antes de agregar índices, conviene analizar las consultas con `EXPLAIN ANALYZE`, porque los índices ocupan espacio y pueden aumentar el costo de las escrituras.

---

<div align="center">

### 🏥 EPS Villavicencio

**Sistema académico de gestión de citas médicas**

*Desarrollado como proyecto final de Bases de Datos.*

</div>
