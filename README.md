# 🏥 EPS Villavicencio

## Sistema local de reserva de citas médicas

Proyecto académico desarrollado con PHP, PostgreSQL, HTML, CSS y JavaScript nativo. Permite registrar afiliados, organizar profesionales y consultorios, reservar citas y consultar resultados de atención.

> La información incluida es ficticia y sirve para demostración. No registres pacientes ni datos clínicos reales.

## Funciones principales

- Registro de solicitudes de afiliación y revisión administrativa.
- Consulta y actualización de datos de afiliados.
- Registro de médicos, especialidades y consultorios.
- Configuración de horarios de atención.
- Reserva, consulta y cancelación de citas.
- Registro del resultado de atención por parte del médico.
- Auditoría automática de cambios en usuarios, afiliados, médicos, historias, catálogos, consultorios, horarios y citas.
- Reprogramación de citas cuando se elimina un horario reservado.
- Consulta integral del historial de citas desde administración.
- Cambio de contraseña para cada perfil y baja segura de médicos.
- Copago calculado automáticamente según el servicio y la categoría del afiliado.
- Filtro de horarios disponibles según el servicio y la especialidad que lo atiende.

## Requisitos

- Linux
- PHP 8.2 o superior con PDO y `pdo_pgsql`
- PostgreSQL 14 o superior

En Ubuntu, instala los componentes necesarios:

```bash
sudo apt update
sudo apt install php php-pgsql postgresql
```

## Instalación local paso a paso

Ejecuta los siguientes comandos en una terminal.

### 1. Borrar la base anterior y crear una nueva

Si quieres empezar desde cero, primero detén el servidor PHP con `Ctrl+C`. El siguiente comando elimina permanentemente todos los datos de `eps_villavicencio`:

```bash
sudo -u postgres dropdb --if-exists eps_villavicencio
sudo -u postgres createdb eps_villavicencio
```

No ejecutes `dropdb` si necesitas conservar información. `proyecto.sql` crea las tablas, catálogos y datos de demostración; impórtalo una sola vez en la base vacía.

### 2. Importar el archivo SQL

Desde la carpeta del proyecto:

```bash
cd ~/Descargas/Proyectofinal-bases
psql -h 127.0.0.1 -U postgres -d eps_villavicencio -v ON_ERROR_STOP=1 -f proyecto.sql
```

`psql` solicitará la contraseña PostgreSQL de `postgres`. El script crea el usuario de conexión `eps_app` con la clave local de demostración `2345`.

### 3. Iniciar la aplicación

```bash
php -S 127.0.0.1:8000
```

Abre <http://127.0.0.1:8000>. El servidor escucha solo en `127.0.0.1`, por lo que la aplicación se usa localmente. Detén el servidor con `Ctrl+C`.

> Esta versión añade el tope de copago a `tarifa` y elimina el campo de monto salarial de `afiliado`. Para conservar una base anterior, haz primero una copia y ejecuta una sola vez, conectado a esa base:
>
> ```sql
> BEGIN;
> ALTER TABLE afiliado DROP COLUMN IF EXISTS ingreso_mensual;
> ALTER TABLE tarifa ADD COLUMN IF NOT EXISTS tope_copago NUMERIC(12,2);
> UPDATE tarifa SET ingreso_min=0, ingreso_max=3501809.99, porcentaje_cobro=11.50, tope_copago=373715 WHERE nombre='Categoría A';
> UPDATE tarifa SET ingreso_min=3501810, ingreso_max=8754525, porcentaje_cobro=17.30, tope_copago=1497644 WHERE nombre='Categoría B';
> UPDATE tarifa SET ingreso_min=8754525.01, ingreso_max=9999999999.99, porcentaje_cobro=23.00, tope_copago=2995409 WHERE nombre='Categoría C';
> ALTER TABLE tarifa ALTER COLUMN tope_copago SET NOT NULL;
> COMMIT;
> ```
>
> Los montos salariales guardados se eliminan; la categoría de cada afiliado permanece. Para una base nueva, importa `proyecto.sql` una sola vez.

`config.php` usa por defecto la base `eps_villavicencio` y el usuario local `eps_app`. Si necesitas otros datos de conexión, puedes definir las variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD` antes de iniciar PHP.

## Cuentas de demostración

Las cuentas de demostración usan la clave `2345`. Para probar el portal de afiliado, selecciona **CC**, escribe **10000001** y la contraseña **2345**. Esa es la afiliación de ejemplo que tiene perfil completo y está activa.

| Perfil | Tipo y número de documento | Clave |
|---|---|---|
| Administrador | CC `99999999` | `2345` |
| Médicos de demostración | CC `90000001` a `90000007` | `2345` |
| Afiliados de demostración | CC `10000001` a `10000007` | `2345` |

El SQL crea 15 cuentas completas: un administrador, siete médicos y siete afiliados. Los tres primeros afiliados están activos para probar las categorías A, B y C; los cuatro restantes quedan pendientes para que se pueda demostrar su aprobación. Todas las claves de demostración son `2345`.

> **Nota del modelo:** La Circular 048 de 2025 del Ministerio de Salud establece estos porcentajes y topes de copago para 2026. El proyecto los usa como ejercicio académico: no separa cotizantes y beneficiarios, no calcula cuotas moderadoras y aplica el porcentaje al valor base del servicio. Consulta la [Circular 048 de 2025](https://www.minsalud.gov.co/Normatividad_Nuevo/Circular%20048%20de%202025.pdf) para el contexto y las reglas completas.

## Perfiles y funciones

### Afiliado

- Solicita su afiliación desde el formulario de registro.
- Consulta y actualiza sus datos personales.
- Reserva citas cuando su afiliación está activa.
- Consulta sus citas, copagos e historia clínica.
- Cancela sus citas programadas; quedan guardadas con estado Cancelada para conservar el historial.
- Cambia su contraseña desde el módulo correspondiente.

### Médico

- Consulta las citas asignadas a su agenda.
- Registra el resultado de la cita atendida.
- Cambia su contraseña desde su panel.
- No crea afiliados ni modifica médicos, consultorios u horarios.

### Administrador

- Busca afiliados, revisa solicitudes y actualiza su estado después de verificar el pago externo.
- Registra y actualiza afiliados.
- Crea cuentas médicas y las asocia con especialidades y consultorios.
- Configura o elimina horarios. Si un horario eliminado tenía una cita, esta se conserva con estado Reprogramacion y se le puede asignar otro espacio disponible.
- Consulta el registro histórico de citas y los últimos cambios de auditoría.
- Da de baja a un médico: bloquea su acceso y conserva sus datos e historial.
- Cambia su contraseña desde su panel.

## Roles y permisos en PostgreSQL

La aplicación identifica los perfiles mediante los registros asociados al usuario: afiliado, médico o administrador. En PostgreSQL, `proyecto.sql` crea los grupos `eps_lectura`, `eps_paciente`, `eps_medico` y `eps_administrador`, además del usuario `eps_app` que utiliza PHP para conectarse a la base.

Los permisos de la interfaz restringen las acciones disponibles para cada perfil. Los grupos PostgreSQL permiten asignar permisos distintos a usuarios que se conecten directamente a la base de datos.

## Reglas del sistema

- SMMLV usado como referencia 2026: $1.750.905 COP.
- Categoría A: menos de 2 SMMLV, hasta $3.501.809,99; porcentaje 11,5 % y tope de $373.715 por evento.
- Categoría B: entre 2 y 5 SMMLV, de $3.501.810 a $8.754.525; porcentaje 17,3 % y tope de $1.497.644 por evento.
- Categoría C: más de 5 SMMLV, superior a $8.754.525; porcentaje 23 % y tope de $2.995.409 por evento.
- El afiliado o el administrador selecciona la categoría según el rango declarado; la aplicación no pide ni almacena un monto de salario. El copago se calcula con el costo del servicio, el porcentaje y el tope guardados en `tarifa`.
- La afiliación permanece Pendiente hasta que el administrador confirme el pago externo.
- Los horarios se muestran según la especialidad correspondiente al servicio elegido.
- Un horario se reserva para una sola cita.
- Un afiliado no puede tener citas pendientes con horarios que se crucen; puede reservar varias el mismo día si una termina antes de que comience la siguiente.
- Los nombres no aceptan números; documentos, teléfonos y fechas se validan en los formularios y en la base de datos.
- El copago toma el porcentaje y el tope por evento definidos para la categoría asignada.
- Los disparadores calculan el copago, actualizan el estado del horario, conservan citas al borrar un horario y guardan auditoría de las operaciones principales.

## Modelo entidad-relación

El siguiente diagrama está escrito en Mermaid, así que GitHub lo representa directamente en el README:

```mermaid
erDiagram
    ESTADO ||--o{ CONSULTORIO : clasifica
    ESTADO ||--o{ HORARIO : clasifica
    ESTADO ||--o{ CITA : clasifica

    USUARIO ||--o| AFILIADO : perfil
    USUARIO ||--o| MEDICO : perfil
    TIPO_DOCUMENTO ||--o{ AFILIADO : identifica
    TIPO_DOCUMENTO ||--o{ MEDICO : identifica
    TARIFA ||--o{ AFILIADO : determina_copago
    AFILIADO ||--o| HISTORIAL : tiene

    CONSULTORIO ||--o{ CONSULTORIO_ESPECIALIDAD : habilita
    ESPECIALIDAD ||--o{ CONSULTORIO_ESPECIALIDAD : disponible_en
    CONSULTORIO_ESPECIALIDAD ||--o{ MEDICO : asignacion

    SERVICIO ||--o{ SERVICIO_ESPECIALIDAD : requiere
    ESPECIALIDAD ||--o{ SERVICIO_ESPECIALIDAD : atiende
    MEDICO ||--o{ HORARIO : programa
    AFILIADO ||--o{ CITA : reserva
    SERVICIO ||--o{ CITA : solicita
    HORARIO o|--o| CITA : ocupa

    AUDITORIA_CAMBIOS {
        bigint id_log PK
        varchar tabla
        varchar accion
        timestamptz fecha
        varchar usuario_bd
        jsonb datos_anteriores
        jsonb datos_nuevos
    }
    ESTADO {
        smallint id_estado PK
        varchar nombre
        varchar ambito
    }
    TIPO_DOCUMENTO {
        smallint id_tipo_doc PK
        varchar sigla UK
        varchar nombre
    }
    TARIFA {
        smallint id_tarifa PK
        varchar nombre UK
        numeric ingreso_min
        numeric ingreso_max
        numeric porcentaje_cobro
    }
    ESPECIALIDAD {
        smallint id_especialidad PK
        varchar nombre UK
        text descripcion
    }
    SERVICIO {
        smallint id_servicio PK
        varchar nombre UK
        text descripcion
        numeric costo_base
    }
    SERVICIO_ESPECIALIDAD {
        smallint id_servicio PK, FK
        smallint id_especialidad PK, FK
    }
    CONSULTORIO {
        smallint id_consultorio PK
        varchar nombre UK
        varchar ubicacion
        smallint capacidad
        smallint id_estado FK
    }
    CONSULTORIO_ESPECIALIDAD {
        smallint id_consultorio PK, FK
        smallint id_especialidad PK, FK
    }
    USUARIO {
        integer id_usuario PK
        varchar username UK
        varchar password_hash
        boolean activo
    }
    AFILIADO {
        integer id_afiliado PK
        integer id_usuario FK, UK
        smallint id_tipo_doc FK
        varchar num_doc
        varchar nombre1
        varchar apellido1
        varchar estado_afiliacion
        smallint id_tarifa FK
    }
    MEDICO {
        integer id_medico PK
        integer id_usuario FK, UK
        smallint id_tipo_doc FK
        varchar num_documento
        varchar nombre1
        varchar apellido1
        smallint id_especialidad FK
        smallint id_consultorio FK
    }
    HISTORIAL {
        integer id_historial PK
        integer id_afiliado FK, UK
        date fecha_apertura
        text alergias
        varchar grupo_sanguineo
        text antecedentes
        text observaciones
    }
    HORARIO {
        integer id_horario PK
        integer id_medico FK
        date fecha
        time hora_inicio
        time hora_fin
        smallint id_estado FK
    }
    CITA {
        integer id_cita PK
        integer id_afiliado FK
        smallint id_servicio FK
        integer id_horario FK, UK nullable
        timestamp fecha_hora_anterior
        varchar medico_anterior
        timestamp fecha_solicitud
        smallint id_estado FK
        numeric valor_cobrado
    }
```

La fuente Mermaid completa, con los campos de las entidades, está en [modelo_entidad_relacion.mmd](modelo_entidad_relacion.mmd). El [INFORME.md](INFORME.md) incluye objetivos, justificación, modelo relacional y diccionario de datos.

## Archivos principales

| Archivo | Propósito |
|---|---|
| `proyecto.sql` | Crea las tablas, relaciones, restricciones, grupos PostgreSQL, disparadores de auditoría y citas, y datos de demostración. |
| `index.php` | Contiene los formularios y los paneles de afiliado, médico y administrador. |
| `Database.php` y `config.php` | Gestionan la conexión PHP con PostgreSQL. |
| `assets/app.css` | Estilos de la interfaz. |
| `assets/favicon.svg` | Ícono de la pestaña del navegador. |
| `modelo_entidad_relacion.mmd` | Diagrama entidad-relación en formato Mermaid. |
| `INFORME.md` | Informe académico, modelo relacional y diccionario de datos. |

> Las claves incluidas son únicamente para datos locales de demostración. Cámbialas antes de usar el sistema con información real y no publiques datos personales.

## Cómo se conecta PHP con PostgreSQL

La conexión está separada en dos archivos. `config.php` lee el host, puerto, nombre de base, usuario y clave desde variables de entorno (y tiene valores locales para esta práctica). `Database.php` incluye ese arreglo, crea el objeto `PDO` y guarda la conexión en una propiedad estática. Así, durante una petición HTTP, las consultas reutilizan una única conexión. PDO está configurado para lanzar excepciones ante errores y devolver filas asociativas.

Las consultas que reciben datos del navegador usan `prepare()` y `execute()` para enviar los valores como parámetros. Las claves se guardan mediante `password_hash()` y se verifican con `password_verify()`; el hash nunca se incluye en los registros de auditoría.

## Módulos del panel

Después del inicio de sesión, el menú permite saltar a las tareas propias del perfil. El administrador tiene Resumen, Afiliados, Médicos y salas, Horarios, Citas y Auditoría. El afiliado tiene Resumen, Agendar cita, Mis citas y Cambiar contraseña. El médico tiene Mi agenda, Mis horarios (solo lectura), Atenciones registradas y Cambiar contraseña. Los permisos de cada acción también se verifican en el backend antes de ejecutar el SQL.

La tabla `auditoria_cambios` almacena fecha, acción, tabla, usuario PostgreSQL y los valores anteriores/nuevos en JSON. La base conserva esa información incluso si se cancela una cita o se elimina su horario. Cada médico puede consultar sus horarios asignados, la disponibilidad, el estado de la cita, el paciente y el servicio; esta vista no ofrece acciones para editar o eliminar horarios. La baja de médicos se hace desactivando la cuenta y retirando sus horarios futuros; no se borran sus citas ni su perfil histórico.


## Administración de usuarios y citas

Los enlaces laterales muestran únicamente el módulo seleccionado. La sección de afiliados reúne el listado completo, búsqueda, creación, edición, cambio de contraseña y eliminación de acceso. Al eliminar un afiliado se desactiva su cuenta, la fila queda marcada en rojo y los datos permanecen para conservar el historial. Las citas futuras de ese afiliado también quedan como `Eliminada` y se resaltan en rojo; las citas atendidas se conservan como historial.

Para obtener los siete perfiles de afiliado de demostración y el estado `Eliminada`, crea nuevamente la base e importa el SQL actualizado, porque `proyecto.sql` es el archivo de instalación inicial.
