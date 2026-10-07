# Dudas y decisiones a validar

Decisiones tomadas durante el desarrollo sin poder consultarlas. Todas se pueden cambiar; cada punto indica qué se ha hecho y qué habría que confirmar.

## Seguridad y acceso

1. **PIN offline.** Para que el cambio de operario funcione sin conexión, la tablet guarda un *digest* SHA-256 del PIN de cada trabajador. Un PIN de 4 dígitos se puede sacar por fuerza bruta a partir del digest, así que es una comodidad, no una medida de seguridad: el login real sigue siendo la sesión del dispositivo. ¿Es aceptable o preferís que el cambio de operario solo funcione con conexión?
2. **Operario de cada operación.** El servidor acepta el `operatorId` que envía una tablet autenticada (si el usuario existe y está activo). Una tablet manipulada podría atribuir acciones a otro trabajador. Se puede endurecer firmando cada operación con el PIN.
3. **Credenciales iniciales** `joanpd0@gmail.com` / `1234`: solo funcionan mientras no haya ningún usuario y obligan a crear el administrador real al momento.
4. **Secreto del QR de fichaje.** Las pantallas de fichaje reciben el secreto HMAC para generar el QR sin conexión. Si una pantalla se pierde, hay que regenerar el secreto en Ajustes (botón "Regenerar"), lo que invalida todos los QR.
5. **Fichar sin QR.** Desde el móvil siempre se pide el QR. En los dispositivos fijos del local (caja y cocina) también se permite fichar sin QR (queda registrado con origen `app`). ¿Lo dejamos así o debe ser siempre con QR?

## Sala y carta

6. **Orden de productos.** En la tablet, "Más pedidos" muestra primero los productos más vendidos; dentro de cada categoría se respeta el orden manual del back office.
7. **Borrar categorías.** No se puede borrar una categoría con productos o subcategorías (hay que moverlos o borrarlos primero) para no perder productos sin querer.
8. **IVA por defecto 10 %** (hostelería). Configurable por producto y en Ajustes.
9. **Arrastrar en pantallas táctiles.** En la planificación de turnos se usa el arrastre nativo del navegador, que en tablets táctiles no siempre funciona. Como alternativa, todo se puede hacer tocando el turno o la celda (diálogo). Si se va a planificar desde tablet, se puede sustituir por una librería con soporte táctil.

## Caja y facturación

10. **Verifactu simulado.** Se genera el registro encadenado (hash con el anterior) y el QR con el formato de la AEAT apuntando al entorno de **pruebas** (`prewww2.aeat.es`). No se envía nada a Hacienda. Para producción hará falta un proveedor/certificado y revisar los campos exactos.
11. **QR de factura antes de sincronizar.** El ticket se imprime al momento aunque no haya conexión. Si el cliente escanea el QR antes de que llegue al servidor, la página muestra "todavía no ha llegado, inténtalo en un rato".
12. **Cobro a partes iguales.** Cada parte genera un ticket proporcional (cantidades fraccionarias). El progreso de las partes se guarda en la caja que cobra; si se cambia de caja a medio cobro, la otra caja no lo ve. Las líneas se marcan pagadas al cobrar la última parte.
13. **Reimprimir.** Desde la caja se pueden reimprimir los tiquets emitidos en esa caja durante la sesión actual (se guarda el documento). Los anteriores se reimprimen desde el back office (PDF).
14. **Informe Z offline.** El informe Z se imprime con los tiquets que tiene la caja; el servidor recalcula el resumen definitivo al recibir el cierre (el del back office es el oficial). El número de Z lo asigna el servidor.
15. **Serie de facturas completas** única para todo el local y reiniciada cada año (`F2026-000001`…); las simplificadas tienen una serie por caja.

## Reservas

16. **Reservas desde la API.** La API (`/api/v1/reservations`) ya permite crear y cancelar reservas con un token de permiso `reservations:write`, pensando en una futura web pública. No hay límite de aforo automático.

## Fichaje

17. **API de Inspección.** Se ha implementado como API con token (`inspection`) que el administrador crea en Gestión → API y entrega a la Inspección. Cada consulta queda auditada. ¿Necesitáis además un acceso web de solo lectura para el inspector?
18. **Horas del día.** Una sesión que cruza la medianoche cuenta en el día en que empezó.
19. **Aviso de salida.** Si alguien no ha fichado la salida X minutos (configurable, 120 por defecto) después del fin de su turno, aparece un aviso en el panel y en el informe.

## Proveedores

20. **Sin OCR.** Las facturas de proveedor se guardan como imagen/PDF con datos introducidos a mano (proveedor, fecha, importe). El OCR queda fuera de alcance, como indicaba el encargo.

## Otros

21. **Impresoras reales.** Solo hay driver simulado; el esqueleto `EscPosPrinterDriver` está preparado para impresoras de red ESC/POS (puerto 9100).
22. **Datáfono.** El cobro con tarjeta solo registra el importe; no hay integración con el TPV bancario.
