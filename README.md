# Módulo Contable — Electrodomésticos Santa Ana

Aplicación web sencilla en **PHP + MySQL**, pensada para correr en **XAMPP** y
editarse en **Visual Studio Code**. Cubre el ciclo contable de forma
automática: Libro Diario con Partida Doble obligatoria, Mayorización
Automática (Libro Mayor), Estados Financieros dinámicos (Balance General y
Estado de Resultados) y un módulo de Liquidez.

## 1. Requisitos
- XAMPP (Apache + MySQL/MariaDB + PHP 7.4 u 8.x)
- Visual Studio Code (opcional, solo para editar el código)

## 2. Instalación paso a paso

1. **Copiar el proyecto**: descomprime esta carpeta `contabilidad_app` dentro
   de `C:\xampp\htdocs\` (en Windows) o `/Applications/XAMPP/htdocs/` (Mac).
   La ruta final debe quedar así: `htdocs/contabilidad_app/index.php`.

2. **Iniciar servicios**: abre el Panel de Control de XAMPP y enciende
   **Apache** y **MySQL**.

3. **Crear la base de datos**:
   - Abre `http://localhost/phpmyadmin`
   - Ve a la pestaña **SQL**
   - Ejecuta `sql/schema.sql` y después `sql/data.sql`.
   - Ejecuta, en este orden, los módulos adicionales (cada archivo ya
     incluye `USE contabilidad_db;`):
       1. `sql/02_cierres_contables.sql` (Cierre contable)
       2. `sql/03_cxc_cxp.sql` (Cuentas por Cobrar y Pagar)
   - Si tu base fue creada antes de corregir el nombre del método de
     valuación (`ALIGACION_DIRECTA`), ejecuta también
     `sql/05_corregir_metodo_valuacion.sql`.
   - Si actualizas una instalación existente de inventario, ejecuta también
     `sql/01_shema_kardex.sql`.
   - Ejecuta una sola vez `php sql/integridad_app.php` para agregar índices
     y restricciones de integridad a una base existente.
   - Si tienes productos antiguos que solo tenían cantidad en la tabla
     `productos`, ejecuta una sola vez `php sql/kardex_sync.php` para crear
     su inventario inicial en el Kardex.
   - Si detectas una diferencia entre lotes y saldos, ejecuta
     `php sql/kardex_sync.php --rebuild`; la reparación es transaccional.
   - Si la base fue restaurada y el Inventario contable no coincide con el
     Kardex, registra manualmente en el Libro Diario el asiento de ajuste
     correspondiente; la aplicación muestra la diferencia y no la corrige
     automáticamente.

4. **Configurar la conexión**:
   `config/db.php` usa el puerto `3307` de este entorno XAMPP. Para producción,
   configura las variables `CONTABILIDAD_DB_*` y usa un usuario MySQL con
   privilegios mínimos en lugar de `root`.

5. **Abrir la aplicación**: entra a
   `http://localhost/contabilidad_app/`

## 3. Acceso al sistema (solo Contador)

El sistema tiene **un único rol permitido: contador**. Cualquier otro
usuario o rol es rechazado en el login.

```
Usuario:    contador
Contraseña: contador123
```

> Recomendación: cambia esta contraseña después de la entrega desde
> `cambiar_password.php`. También puedes cambiar los valores de conexión mediante
> las variables `CONTABILIDAD_DB_HOST`, `CONTABILIDAD_DB_NAME`,
> `CONTABILIDAD_DB_USER`, `CONTABILIDAD_DB_PASS` y `CONTABILIDAD_DB_PORT`.

## 4. Estructura del proyecto

```
contabilidad_app/
├── assets/
│   ├── css/style.css        Estilos (paleta blanco, negro, gris, azul, celeste)
│   └── img/logo.png         Logo de Electrodomésticos Santa Ana
├── config/
│   └── db.php                Conexión a MySQL
├── includes/
│   ├── auth.php               Control de acceso (solo rol contador)
│   ├── functions.php          Funciones contables (saldos, formato de moneda...)
│   ├── security.php            Sesión, CSRF y validación de datos
│   ├── header.php / footer.php Plantilla común / menú
├── sql/
│   ├── schema.sql              Creación de la base de datos y tablas
│   ├── data.sql                 Catálogo de Cuentas (Manual de Aplicación Comercial)
│   ├── 01_shema_kardex.sql      Kardex para instalaciones anteriores
│   ├── integridad_app.php       Índices y restricciones de integridad
│   └── kardex_sync.php          Migración/reparación del Kardex
├── index.php                    Login
├── login_process.php            Valida usuario + rol contador
├── logout.php
├── cambiar_password.php         Cambio seguro de contraseña
├── dashboard.php                 Panel principal con indicadores
├── catalogo_cuentas.php           Catálogo de Cuentas
├── libro_diario.php                Registro de asientos (Partida Doble)
├── guardar_asiento.php              Procesa y valida la Partida Doble
├── libro_mayor.php                   Mayorización automática
├── estados_financieros.php            Balance General y Estado de Resultados
└── liquidez.php                        Razones financieras de liquidez
```

## 5. Cómo funciona el ciclo contable

1. **Libro Diario**: se ingresa fecha, concepto y líneas de Debe/Haber. El
   formulario valida en el navegador (JavaScript) y el servidor
   (`guardar_asiento.php`) valida de nuevo que `Σ Debe = Σ Haber`; si no
   cuadra, **el sistema bloquea el guardado**.

2. **Libro Mayor**: cada vez que se guarda una partida, el saldo de cada
   cuenta se recalcula en tiempo real a partir de la tabla
   `detalle_asientos` (no se guardan saldos "fijos": siempre se consolidan
   al vuelo, evitando inconsistencias).

3. **Estados Financieros**: se generan dinámicamente clasificando las
   cuentas según el **primer dígito** de su código. Puedes consultar un
   rango de fechas; el Balance General se acumula hasta la fecha de corte y
   el Estado de Resultados muestra el período seleccionado:
   - **Balance General**: código `1` (Activo) = código `2` (Pasivo) +
     código `3` (Capital Contable / Patrimonio).
   - **Estado de Resultados**: código `5` (Ingresos) − código `4` (Costos
     y Gastos) = Utilidad del Ejercicio.

4. **Liquidez**: se calculan automáticamente la Razón Corriente, la Prueba
   Ácida, la Razón de Efectivo y el Capital de Trabajo Neto, usando las
   cuentas marcadas como `corriente = 1` (grupos 11 y 21 del catálogo).

5. **Inventario y Kardex**: los productos activos muestran el saldo del
   Kardex. Las entradas, salidas, compras y ajustes actualizan el Kardex
   dentro de una transacción; la cantidad y el costo no se editan libremente.

## 6. Base legal y normativa (El Salvador)

El catálogo de cuentas y el tratamiento contable se apegan a:
- **Código de Comercio de El Salvador**: obligación de llevar Libro Diario,
  Libro Mayor y Libro de Estados Financieros debidamente legalizados.
- **Normas Internacionales de Información Financiera para las Pequeñas y
  Medianas Entidades (NIIF para las PYMES)**, marco contable de uso
  extendido por las PYMES salvadoreñas.
- **Ley de Impuesto a la Transferencia de Bienes Muebles y a la Prestación
  de Servicios (Ley de IVA)**: de ahí las cuentas de Crédito Fiscal – IVA
  (1109) y Débito Fiscal – IVA (2108) del catálogo.
- **Ley de Impuesto Sobre la Renta**: cuentas de Pago a Cuenta ISR (1108),
  Retenciones (2104) e Impuesto Sobre la Renta por Pagar (2106).

Estas referencias son de carácter educativo/orientativo para el ejercicio
académico; no sustituyen la asesoría de un profesional autorizado.

## 7. Notas para ampliar el proyecto

- El catálogo de `sql/data.sql` incluye las cuentas de mayor uso del Manual
  de Aplicación de Cuentas Comercial. Puedes agregar más subcuentas
  siguiendo la misma estructura de dígitos (1=Grupo, 2=Rubro, 4=Mayor,
  6=Subcuenta, 8=Detalle, 10=Analítica) insertando nuevas filas en la
  tabla `cuentas`.
- Puedes agregar más usuarios contadores desde `phpMyAdmin`, generando el
  hash de la contraseña con PHP: `password_hash('tu_clave', PASSWORD_BCRYPT)`.
- El diseño usa una paleta de colores blanco, negro, gris, azul y celeste,
  definida en `assets/css/style.css` (variables CSS al inicio del archivo).
- Los formularios de login, asientos e inventario utilizan token CSRF.
- `debug_login.php` está deshabilitado para no exponer hashes de contraseña.
