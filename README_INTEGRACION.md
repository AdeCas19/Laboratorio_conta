# Kardex legal (Art. 142, 142-A y 143 del Código Tributario de El Salvador)

## Qué cambia respecto al sistema actual

Antes de esta corrección, `productos.cantidad` y `productos.valor_unitario`
solo guardaban el estado actual y podían sobrescribirse al editar un
producto. Eso no era un registro de control de inventarios según la ley:
no había historial ni trazabilidad para demostrar cómo se llegó al número
si Hacienda audita (Art. 173 literal b: la Administración puede
"confrontar en cualquier momento los inventarios con las existencias
reales").

Con este cambio, **el kardex (`movimientos_inventario`) pasa a ser la única
fuente de verdad**. `productos.cantidad` y `productos.valor_unitario` se
mantienen únicamente como campos de compatibilidad y se actualizan desde el
último saldo del Kardex.

## Flujo de integración con `inventario.php`

1. **Al crear un producto nuevo con existencia inicial** (acción `agregar`):
   en vez de solo hacer `INSERT INTO productos`, después de insertar el
   producto llama a `registrarEntrada()` con
   `tipo_movimiento => 'INVENTARIO_INICIAL'`. Esto es obligatorio según el
   Art. 142: *"está obligado a practicar inventario físico al comienzo del
   negocio"*.

2. **Cuando se edita un producto**: la cantidad y el costo se muestran
   como campos de solo lectura. La existencia se modifica exclusivamente
   con las acciones **Entrada** o **Salida** del inventario, que llaman a
   `registrarEntrada()` o `registrarSalida()` y actualizan el Kardex dentro
   de una transacción.

3. **Contabilización**: el módulo de Inventario actualiza el Kardex, pero no
   crea asientos contables automáticamente. El contador debe registrar el
   asiento en el Libro Diario y usar `costo_total_salida` para el Costo de
   Venta. La pantalla de Inventario muestra una advertencia cuando la cuenta
   1105 no coincide con el valor del Kardex.

4. **Desactivar producto**: la aplicación no ejecuta `DELETE FROM productos`.
   Si el producto tiene saldo en el Kardex, primero se registra una salida o
   ajuste; cuando el saldo llega a cero, el producto se marca como inactivo
   y se conserva todo su historial.

## Por qué PEPS y no otro método

El Art. 143 permite 4 métodos. Se implementaron PEPS y Promedio Ponderado
porque son los dos reconocidos también por la NIC 2 (párrafo 25) que exige
el Consejo de Vigilancia de la Profesión de Contaduría Pública vía el
Código de Comercio (Art. 443–444) — es decir, son los únicos dos que
sirven tanto para efectos **tributarios** como para efectos
**financieros/NIIF** al mismo tiempo, sin tener que llevar dos
valuaciones distintas en paralelo.

- **PEPS** es mejor si vendes electrodomésticos, tecnología o cualquier
  producto donde los precios de compra suben con el tiempo (inflación) o
  donde te interesa mover primero el stock más viejo.
- **Promedio Ponderado** es más simple de explicar y auditar si compras
  con mucha frecuencia al mismo proveedor a precios similares.

El método se define **una sola vez** en `config_inventario` y el código
está diseñado para que cambiarlo no sea trivial desde la UI — refleja la
restricción legal del Art. 143 de que un cambio de método requiere
autorización previa de la Administración Tributaria.

## Contabilización del inventario

Actualmente el Kardex y el Libro Diario son módulos separados: el Kardex
registra el movimiento físico y el contador debe crear el asiento contable.
La aplicación muestra la diferencia entre ambos saldos para que no se pase
desapercibida. La generación automática de asientos de compra, venta, IVA y
costo debe implementarse junto con el módulo de Ventas/Compras, porque
requiere definir cuentas, impuestos y política de valuación.


- Generar el **Formulario F-983** (detalle de inventario físico) que exige
  el Art. 142 para contribuyentes con ingresos ≥ 2,753 salarios mínimos
  mensuales, presentado en los dos primeros meses del año.
- Guardar los movimientos por **10 años** (Art. 147 CT) — asegúrate de que
  tu política de backups/retención de base de datos cumpla esto; no basta
  con no borrar filas en la app, la base de datos completa debe
  respaldarse por ese período.
- Registrar el **acta firmada** del inventario físico anual (Art. 142
  inciso 3) — esto es un documento/proceso administrativo, no algo que el
  software resuelva solo, pero el sistema puede generar el PDF con el
  detalle para que se imprima y firme.