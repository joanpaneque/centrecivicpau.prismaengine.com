# Encargo: TPV y gestión integral para el bar del Centre Cívic de Pau

Eres un desarrollador senior full stack. Vas a construir una aplicación web completa de gestión para un bar restaurante. Lee toda la especificación antes de empezar, propón la arquitectura y el modelo de datos, y después desarrolla por fases (ver final del documento).

**Stack:** [INDICA AQUÍ EL STACK. Si no se indica, propón uno y justifícalo brevemente antes de empezar.]

---

## 0. Principios que mandan sobre todo lo demás

1. **UX de mínimos clics.** Cada acción frecuente (tomar comanda, cobrar, fichar) debe hacerse con el menor número de toques posible. Si dudas entre dos diseños, elige el que requiera menos pasos.
2. **Usuario no técnico.** El dueño y parte del personal no son hábiles con la tecnología. Todo debe ser obvio sin explicación: botones grandes, textos claros, iconos reconocibles, nada escondido en menús secundarios.
3. **Pensado para tablet** (táctil, dedos, uso de pie y con prisa). Objetivos táctiles de mínimo 48px. El back office en ordenador puede ser más denso.
4. **Confirmaciones solo en acciones destructivas.** Para lo demás, ofrecer "Deshacer" durante unos segundos en lugar de diálogos.
5. **Nunca se pierde un dato**, aunque se caiga el wifi.
6. **Bilingüe catalán / castellano** en toda la interfaz. Cada usuario elige su idioma en su perfil. Los nombres de productos y categorías deben poder introducirse en ambos idiomas (si falta uno, se muestra el otro).

---

## 1. Arquitectura

- Aplicación web alojada en la nube (servidor central único).
- **PWA instalable** en tablets, móviles y ordenador (manifest, iconos, service worker).
- **Funcionamiento offline en tablets y caja:**
  - Al tener conexión, el dispositivo descarga y cachea todo lo necesario: carta, categorías, fotos, mesas, zonas, usuarios, configuración, reservas del día.
  - Sin conexión, la app sigue funcionando con normalidad.
  - Toda acción que deba llegar al servidor se guarda en una **cola local persistente** (IndexedDB) y se reintenta en segundo plano con backoff hasta que se confirme.
  - Cada operación lleva un **UUID generado en el cliente** (idempotencia: un reintento nunca duplica una comanda, un cobro o un fichaje).
  - Indicador discreto y siempre visible del estado: conectado / sin conexión / X operaciones pendientes de enviar.
  - Al reconectar, sincronizar en ambos sentidos y resolver conflictos de forma sensata (documenta la estrategia que elijas).
- Las mesas y comandas deben reflejarse en tiempo real entre dispositivos cuando hay conexión (websockets o similar).

---

## 2. Roles y usuarios

- **Administrador:** acceso total (back office, caja, configuración, informes de fichaje, facturas de proveedores).
- **Camarero / trabajador:** sala, comandas, reservas, fichaje propio, ver sus turnos.
- **Dispositivo de cocina/barra:** sesión de tipo pantalla de cocina, sin acceso a nada más.

**Primer arranque (credenciales por defecto):**
- Si **no existe ningún usuario** en la base de datos, se puede iniciar sesión con usuario `joanpd0@gmail.com` y contraseña `1234`.
- Al entrar así, la app obliga a introducir un **correo electrónico nuevo y una contraseña nueva** (con confirmación) antes de hacer nada más. Con esos datos se crea el **primer usuario administrador**.
- En cuanto existe al menos un usuario, esas credenciales por defecto **dejan de funcionar para siempre**.
- Esta comprobación se hace **en el servidor** (nunca solo en el cliente), y la creación del primer admin debe ser atómica para que no puedan crearse dos admins iniciales a la vez.

**Alta y login:**
- El administrador crea trabajadores desde el ordenador (nombre, idioma, rol, PIN opcional).
- Para cada trabajador se genera un **QR de inicio de sesión**. Se escanea con la tablet y la sesión queda iniciada de forma persistente.
- Cambio rápido de camarero en una tablet compartida (selector de usuario + PIN de 4 dígitos), sin cerrar la app.
- Cada acción queda asociada al usuario que la hizo.

---

## 3. Sala (vista principal de la tablet)

**Zonas iniciales (seed):**
| Zona | Mesas |
|---|---|
| Terraza exterior | 30 |
| Porche | 10 |
| Comedor | 20 |
| Barra | 7 taburetes (configurable entre 5 y 7 o más) |

- Pestañas grandes por zona arriba. Plano visual de mesas debajo.
- Cada mesa tiene **4 plazas por defecto** (editable).
- **Editor de plano:** arrastrar para mover mesas, añadir y quitar mesas, renombrarlas o numerarlas. Hay que poder añadir **mesas auxiliares** (del Ayuntamiento) en cualquier zona de forma rápida, y marcarlas como auxiliares para quitarlas fácilmente después.
- **Estado por color:** libre, ocupada, reservada, pendiente de cobro. Mostrar el importe acumulado y el tiempo desde la apertura.
- Acciones de mesa: cambiar de mesa, juntar mesas, dividir cuenta (por productos o a partes iguales).
- La barra funciona igual que las mesas (cada taburete o "cuenta de barra").

---

## 4. Toma de comandas

Flujo objetivo: **tocar mesa → tocar productos → enviar.** Nada más.

- Productos en cuadrícula grande, por categorías. Con **foto** si la tiene; si no, **nombre sobre un color** asignado a la categoría o al producto.
- **Buscador instantáneo** siempre visible (busca mientras se escribe, en ambos idiomas, ignorando acentos).
- Los **más pedidos** aparecen primero en cada categoría (y una pestaña de "Frecuentes").
- Tocar varias veces un producto suma unidades. Gesto o botón claro para restar.
- **Modificadores** configurables por producto o categoría (ej.: descafeinado, leche de avena, poco hecho, sin cebolla) y **nota libre** por línea.
- **Pases:** marcar líneas como 1º / 2º y botón "Marchar segundos".
- **Productos agotados:** marcarlos desde la tablet con un toque largo; quedan deshabilitados en todos los dispositivos.
- **Alérgenos:** visibles en cada producto (los 14 alérgenos oficiales de la UE, con iconos).
- Los camareros pueden **anular líneas y aplicar descuentos**. Todo queda registrado (quién, cuándo, motivo opcional).

**Envío a producción:**
- Cada categoría o producto se asigna a un destino: **barra** o **cocina** (o cualquier otro destino configurado).
- Al enviar, cada destino recibe solo lo suyo.

---

## 5. Impresoras y pantallas de cocina

- **Varias impresoras configurables:** nombre, tipo (cocina, barra, tickets de cliente), categorías asignadas, y campos de conexión (IP, puerto, modelo) que de momento no se usan.
- **De momento la impresión es SIMULADA:** al enviar, mostrar confirmación "Enviado a [impresora]" y guardar un registro de envíos con una **previsualización realista** del ticket de comanda (formato de impresora térmica de 80mm). Dejar la capa de impresión desacoplada para conectar impresoras reales más adelante (ESC/POS).
- **Pantallas de cocina (KDS) opcionales:** un destino puede configurarse como impresora, pantalla o ambas. La pantalla muestra las comandas en tarjetas por orden de llegada, con mesa, tiempo transcurrido y estado (pendiente → en preparación → listo), y se cambia con un toque. Avisar a la sala cuando algo está listo.

---

## 6. Carta, categorías y menús (back office, ordenador)

**Categorías iniciales (seed, con subcategorías editables):**
- Cafés: café solo, cortado, café con leche, capuchino, carajillo, carajillo especial
- Licores
- Refrescos
- Cervezas (preparado para 20 a 30 referencias)
- Bocadillos
- Entrantes
- Segundos (carnes)
- Pescado
- Para llevar
- Tapas (con subcategorías)
- Menús (menú del día, menú compartido y otros)

**Producto:** nombre (ca/es), categoría, precio, IVA (por defecto 10%, editable), foto opcional, color, alérgenos, modificadores, destino de producción, disponible sí/no, orden.

**Gestión:** crear, editar, duplicar, reordenar arrastrando y activar/desactivar productos y categorías. Subida de fotos con recorte automático.

### Menú del día (prioridad de UX máxima)
- Editor **muy sencillo y visual**, pensado para alguien poco técnico.
- Totalmente personalizable: nombre, precio, número y nombre de las secciones (primeros, segundos, postre, bebida, café o lo que se quiera), platos de cada sección y qué está incluido.
- **Duplicar / clonar** un menú existente con un clic y editar solo lo que cambia (ej.: "Duplicar menú de ayer").
- Programarlo por fechas o días de la semana.
- En la tablet, pedir un menú guía al camarero paso a paso (elige primero → elige segundo → ...) con el mínimo de toques, y cada plato va a su destino de producción correspondiente.

---

## 7. Suplemento de terraza

- Un **% general** configurable (puede ser 0%) que se aplica a las consumiciones de las mesas de la zona terraza.
- Aparece como línea separada en el ticket ("Suplemento terraza X%"), también cuando es 0% si así se configura (opción mostrar/ocultar cuando es 0).

---

## 8. Caja y cobro

- **El cobro solo se hace desde la caja** (ordenador o dispositivo con rol de caja). Las tablets no cobran: pueden marcar "pedir la cuenta" y la mesa aparece como pendiente de cobro en la caja.
- Formas de pago: **efectivo** (con introducción del importe entregado y cálculo del cambio) o **tarjeta**. También pago mixto.
- Sin registro de propinas.

### Ticket (factura simplificada)
Debe incluir:
- **Logo** (subible desde la configuración)
- Datos del emisor: **Juan Paneque Domingo, NIF 40457683Q, Carrer Sant Pere, 12, 17494 Pau (Girona)**, editables desde la configuración
- Serie y número correlativo, fecha y hora
- Mesa y camarero
- Líneas, suplemento de terraza, base imponible, IVA desglosado por tipo, total y forma de pago
- **QR de previsualización tipo Verifactu** (de momento solo maquetado y simulado; no se envía nada a la AEAT. Dejar la capa preparada para integrarlo más adelante)
- **QR "Solicita tu factura"** (ver abajo)

Previsualización del ticket en pantalla antes de imprimir. Impresión simulada como en el punto 5.

**Numeración:** debe ser correlativa y sin duplicados aunque la caja trabaje offline (propón una solución, por ejemplo una serie por dispositivo de caja).

### Factura completa a petición del cliente
- El cliente escanea el QR del ticket con su móvil y llega a una **página pública** (sin login, protegida por un token único del ticket) donde introduce sus datos fiscales (nombre o razón social, NIF, dirección, email).
- Se genera la **factura completa** asociada a ese ticket (PDF descargable y envío por email).
- El ticket no puede facturarse dos veces. El administrador ve en el back office las facturas completas emitidas.

### Cierre de caja
- Apertura con fondo de caja inicial.
- Cierre con resumen del día: ventas totales, por forma de pago, número de tickets, descuentos y anulaciones.
- **Recuento de efectivo** (mejor con desglose por billetes y monedas) y cálculo del **descuadre**.
- Informe de cierre (tipo Z) imprimible (simulado) y guardado en el historial.

---

## 9. Reservas

- Las crea el personal desde la tablet o desde el ordenador (de momento no hay reservas web, pero hay que dejarlo preparado con una API para añadirlas más adelante).
- Datos: nombre, teléfono, número de personas, fecha, hora, zona o mesa (opcional), notas.
- Vista de día tipo agenda y vista sobre el plano: la mesa aparece como reservada antes de la hora.
- Al llegar el cliente, con un toque, la reserva abre la mesa.
- Estados: confirmada, sentada, no presentada, cancelada.

---

## 10. Fichaje de trabajadores (cumplimiento legal)

Base legal vigente: art. 34.9 del Estatuto de los Trabajadores (RDL 8/2019). Además hay un Real Decreto de registro horario digital en tramitación (aún no aprobado) que exigirá un sistema digital, auditable y no manipulable con acceso remoto para la Inspección. **Diséñalo ya cumpliendo esos requisitos.**

**Formas de fichar:**
1. Desde la app en su móvil con su usuario (botón enorme "Entrada / Salida / Pausa").
2. Escaneando un **QR mostrado en un dispositivo del local**. El QR es **dinámico** (cambia cada 30 a 60 segundos) para que no se pueda fichar desde casa con una foto.

**Requisitos:**
- Registrar hora y minuto exactos de entrada, salida y **pausas**, por trabajador y día.
- **Registro inmutable:** nada se borra ni se sobrescribe. Cualquier corrección la hace el administrador y queda registrada como corrección (valor original, nuevo valor, quién, cuándo y motivo obligatorio).
- **Identificación inequívoca** del trabajador en cada fichaje.
- Cálculo de horas trabajadas, **horas extra** y comparación con el turno planificado.
- Avisos: olvido de fichar la salida, fichaje fuera de turno.
- **Cada trabajador puede consultar su historial** de fichajes en todo momento.
- **Conservación mínima de 4 años.**
- **Exportación** para la Inspección de Trabajo (PDF y Excel/CSV por trabajador y rango de fechas, con datos de la empresa y del trabajador). Dejar preparada una API de consulta para un futuro acceso remoto de la Inspección.
- **Sin geolocalización ni biometría** (por protección de datos, no hace falta).
- Funciona offline: se guarda con la hora del dispositivo y se marca como "sincronizado más tarde".

---

## 11. Planificación de turnos

- Calendario semanal y mensual en el back office: asignar turnos a trabajadores arrastrando.
- Plantillas de turno (mañana, tarde, partido, etc.) y **copiar semana anterior**.
- Cada trabajador ve sus turnos en el móvil.
- Vista de horas planificadas vs. fichadas por trabajador.

---

## 12. Facturas de proveedores

- Solo administrador, pensado para el móvil.
- **Escanear con la cámara** (con recorte y mejora automática de la imagen si es posible) o **subir un archivo** (PDF o imagen).
- Campos opcionales: proveedor, fecha, importe, notas.
- Listado con búsqueda, filtro por fecha y proveedor, y visor del documento.
- De momento solo se guardan. Sin OCR ni integraciones (se harán más adelante; deja el modelo preparado).

---

## 13. Configuración (back office)

Datos fiscales y logo, zonas y mesas, % suplemento de terraza, IVA por defecto, impresoras y pantallas, destinos de producción, usuarios y QR de login, idioma por defecto, series de tickets.

---

## 14. Fuera de alcance por ahora

- Informes de ventas avanzados.
- Integración real de impresoras, Verifactu y datáfono.
- Reservas desde la web pública.
- OCR o contabilidad de facturas de proveedores.

Pero diseña todo desacoplado para poder añadirlo sin rehacer nada.

---

## 15. Fases de entrega

1. Arquitectura, modelo de datos, autenticación, roles, i18n ca/es y PWA con modo offline y cola de sincronización.
2. Sala, mesas, editor de plano y comandas en tablet (con seed de datos).
3. Carta, categorías, menús y editor del menú del día.
4. Impresoras simuladas y pantallas de cocina.
5. Caja, tickets, factura a petición por QR y cierre de caja.
6. Reservas.
7. Fichaje y planificación de turnos.
8. Facturas de proveedores.

Al terminar cada fase: resume qué has hecho, cómo probarlo y qué decisiones has tomado. Antes de empezar, hazme las preguntas que consideres imprescindibles (máximo 5).

---

## Registro de desarrollo

> Apuntes del desarrollo hechos durante la implementación. Las dudas y decisiones pendientes de validar están en [`DUDAS.md`](DUDAS.md) y la arquitectura técnica en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

### Estado general (7 de octubre de 2026)

Las 8 fases están implementadas. Comprobaciones finales en verde:

| Comprobación | Resultado |
| --- | --- |
| `php artisan test` | 111 tests, 529 aserciones, todo OK |
| `vendor/bin/pint --test` | OK |
| `vendor/bin/phpstan analyse` (nivel 7) | 0 errores |
| `npm run lint:check` (ESLint) | OK |
| `npm run types:check` (vue-tsc) | OK |
| `npm run build` | OK |

### Cómo probarlo

1. `sail up -d` y `sail npm run dev` (la app escucha en el puerto 80: <http://localhost>).
2. Migraciones y datos de ejemplo: `sail artisan migrate --seed` (zonas, mesas, carta, menús del día, impresoras simuladas, destinos y plantillas de turno). Personal de prueba opcional: `sail artisan db:seed --class=DemoStaffSeeder` (PIN 1111, 2222 y 3333).
3. Primer acceso: mientras **no exista ningún usuario**, se entra con `joanpd0@gmail.com` / `1234`; el sistema obliga a crear el administrador real en `/configuracion-inicial` y esas credenciales dejan de funcionar.
4. Back office en `/panel` y `/gestion/...` (ordenador). TPV en `/tpv` (tablet, móvil, caja, cocina o pantalla de fichaje). La primera vez que se abre `/tpv` en un aparato se registra como dispositivo y se elige su tipo.

### Fase 1 — Base

- Laravel 13 + Inertia v3 + Vue 3 + TypeScript + Tailwind 4, PostgreSQL (Sail), Fortify, Sanctum, Reverb.
- Roles `admin`, `staff` (personal de sala/barra) y `kitchen`; usuarios activables/desactivables, cambio de contraseña obligatorio, PIN de 4 dígitos para cambiar de operario en la tablet, login por QR personal (token rotatorio, imprimible desde Usuarios).
- i18n catalán (por defecto) y castellano en toda la interfaz, en los textos de la carta (`{ca, es}`) y en los mensajes del servidor; cada usuario tiene su idioma.
- PWA offline-first: IndexedDB (Dexie) con la instantánea de datos, cola de operaciones (`outbox`) con UUID idempotentes, reducers optimistas en el cliente que replican los handlers del servidor, sincronización por `push`/`pull` con cursor, aviso en tiempo real por Reverb y polling de respaldo. Service worker (`public/sw.js`) con precache del manifiesto de Vite y `manifest.webmanifest`.
- Auditoría (`audit_logs`) de acciones sensibles.

### Fase 2 — Sala y comandas

- Zonas y mesas con editor de plano (arrastrar, redimensionar, formas, mesas auxiliares temporales y limpieza de auxiliares libres).
- Editor visual táctil del plano (botón "Edita el plànol", solo administradores y con conexión): tocar para seleccionar, arrastrar para mover, tiradores para cambiar el tamaño, tirador circular para girar (saltos de 15°), duplicar, borrar, traer al frente/enviar al fondo y mover con las flechas del teclado. Además de mesas (cuadrada, redonda, rectangular, taburete, auxiliar) se pueden poner elementos decorativos: barra, pared, puerta (con el arco de apertura), ventana, columna, planta, cocina, lavabos, escaleras y texto, con texto y color editables. Los cambios se guardan al soltar y llegan al resto de tablets al momento; si falla, el elemento vuelve a su sitio. Los elementos se guardan en la tabla `floor_elements` y las mesas tienen ahora `rotation`.
- Estados de mesa: libre, ocupada, cuenta pedida, reservada, platos listos.
- Cambiar de mesa y juntar mesas desde la comanda; dividir la cuenta (por productos o a partes iguales) se hace al cobrar en caja. Venta rápida de barra/para llevar.
- Toma de comandas en tablet: buscador sin acentos, más pedidos primero, categorías, modificadores (obligatorios/múltiples), notas, pases (1.º/2.º/postre) con retención del segundo y "marchar", agotados, alérgenos, anular con motivo (imprime anulación en cocina) y descuentos por línea o por cuenta.
- Seeders con la sala del Centre Cívic.

### Fase 3 — Carta y menú del día

- Back office de categorías (árbol, color, destino, reordenar arrastrando) y productos (precio con IVA incluido, IVA, alérgenos, color, foto con recorte cuadrado, duplicar, reordenar, agotado).
- Grupos de modificadores asignables a categorías o productos.
- Editor de menús del día: secciones (primeros, segundos, postres, bebida…) con número de elecciones, pase de cocina, platos de la carta o texto libre, suplementos, programación (siempre / días de la semana / fechas). En la tablet, asistente paso a paso.

### Fase 4 — Impresión y cocina

- Destinos de producción (cocina, barra…) en modo impresora, pantalla o ambos.
- Impresoras **simuladas** con driver desacoplado (`PrinterDriver`: `SimulatedPrinterDriver` y esqueleto `EscPosPrinterDriver`). Cada impresión queda en `print_jobs` y se puede ver en el registro con vista previa de papel de 80 mm.
- KDS en tiempo real: tarjetas por pedido con tiempo transcurrido (colores a los 10 y 20 min), filtro por destino, empezar / listo / servido / deshacer, recuperación de servidos y sonido. Cuando un pedido está listo, la sala recibe un aviso.

### Fase 5 — Caja

- Dispositivos de tipo caja con **serie de tickets propia** (numeración local que funciona sin conexión; si el número ya existe, el servidor lo renumera y lo audita).
- Apertura con fondo de caja; cobro en efectivo (entregado y cambio), tarjeta o mixto; cobrar todo, por productos o a partes iguales.
- Factura simplificada con desglose de IVA, suplemento de terraza, QR tributario Verifactu (**simulado**, encadenado con hash) y QR para pedir la factura completa.
- Factura completa a petición: página pública `/factura/{token}` (sin login) donde el cliente rellena sus datos fiscales y descarga el PDF (y lo recibe por correo si lo indica). Si el ticket aún no ha llegado al servidor, muestra "inténtalo más tarde".
- Informe X (parcial) y cierre Z con recuento por billetes y monedas, esperado, diferencia, totales por forma de pago, IVA, descuentos, suplemento y anulaciones. Histórico de cierres con PDF en el back office.
- Back office de tickets (detalle, PDF, emitir factura) y facturas (reenviar).

### Fase 6 — Reservas

- Agenda por días en el TPV: crear/editar, estados (confirmada, sentados, acabada, cancelada, no presentado), sentar en una mesa (abre la comanda vinculada a la reserva) y aviso en el plano a partir del margen configurado.
- API REST con Sanctum (`/api/v1/reservations`) protegida por permisos del token, preparada para una futura web pública.

### Fase 7 — Fichaje y turnos

- Fichaje **inmutable**: los registros no se pueden modificar ni borrar (evento del modelo + trigger en PostgreSQL), van encadenados con hash SHA-256 por trabajador y guardan la hora real, la de recepción y si se sincronizaron tarde.
- Las correcciones del administrador se guardan aparte (modificar, añadir, anular) con motivo obligatorio, autor y hash; el original siempre se conserva.
- QR dinámico en una pantalla de fichaje (HMAC del secreto + ventana de tiempo, renovación cada 30–60 s) que funciona sin conexión. El trabajador lo escanea con el móvil (enlace directo o lector integrado).
- Cálculo de horas trabajadas, pausas, horas extra frente a lo planificado y avisos (falta la salida, fichaje fuera de turno).
- Exportación PDF, CSV y XLSX, y **API de Inspección** (`/api/inspeccion/v1`) con token de permiso `inspection`; cada acceso queda auditado.
- Planificación de turnos: vista semanal y mensual por trabajador, plantillas, arrastrar para mover (Ctrl para copiar), copiar la semana anterior. Cada trabajador ve sus turnos en el TPV.

### Fase 8 — Facturas de proveedores

- Captura con la cámara (o subida de imagen/PDF), recorte por esquinas, rotación y mejora (escala de grises, niveles y contraste) en el navegador, compresión en el servidor y almacenamiento privado.
- Listado con filtros (proveedor, fechas), visor y edición de los datos (proveedor, fecha, importe, notas).

### Asistente de IA

- Chat con GPT-5.6 Luna a través de OpenRouter (`OPENROUTER_API_KEY` en `.env`). El modelo recibe el manual completo `docs/MANUAL.md` (hay que mantenerlo al día: regla de Cursor `.cursor/rules/assistant-manual.mdc`).
- Historial de conversaciones por usuario (crear, continuar, renombrar, borrar). Respuesta en streaming. Disponible en Gestión (`/gestion/asistente`) y en el TPV (pestaña Assistent IA). El personal de sala puede usarlo desde el TPV; la página de Gestión es solo para administradores.
- Si falta la clave, la interfaz avisa y no se puede preguntar.

### Tests añadidos

- `tests/Feature/Tpv/SyncTest.php`: bootstrap, push idempotente, rechazo de operaciones desconocidas, permisos de cocina, ciclo completo de caja (apertura, ticket con IVA, cierre Z), dispositivo de caja obligatorio y QR de fichaje válido/caducado.
- `tests/Feature/InvoiceRequestTest.php`: página pública, estado pendiente, emisión de factura, PDF y no duplicar.
- `tests/Feature/TimeTrackingTest.php`: inmutabilidad, cadena de hash, idempotencia, cálculo de horas, correcciones con motivo y exportación auditada.
- `tests/Feature/Api/ApiTokenTest.php`: permisos de token, reservas por API, API de Inspección auditada y creación de tokens.
- `tests/Feature/AssistantTest.php`: permisos, clave ausente, manual, chat con historial, aislamiento entre usuarios y conversación vacía descartada.
- `tests/Feature/Tpv/FloorPlanTest.php`: permisos del editor, alta/cambio/baja de elementos, validaciones, guardado de posición, tamaño y giro, y elementos y giro incluidos en el snapshot del TPV.
- `tests/Feature/Admin/BackOfficeTest.php`: todas las páginas del back office, carta (CRUD, duplicar, no borrar categorías con productos), validaciones, zonas, turnos (mover/copiar) y facturas de proveedores.
- Se han actualizado 4 tests heredados por cambios intencionados: el login lleva a `/tpv`, `/gestion` redirige al panel, el panel solo es para administradores y el mensaje de la orden `register` sale en el idioma de la aplicación.
