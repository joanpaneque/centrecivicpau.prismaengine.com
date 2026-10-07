# Arquitectura — TPV Centre Cívic Pau

## Visión general

```
 Tablets / móviles / caja / KDS / pantalla de fichaje         Ordenador (back office)
 ┌──────────────────────────────────────────┐                 ┌────────────────────────┐
 │ /tpv  (Vue SPA dentro de Inertia)        │                 │ /panel, /gestion/*     │
 │  ├─ estado reactivo (pos/store.ts)       │                 │ páginas Inertia (Vue)  │
 │  ├─ IndexedDB (Dexie): datos + outbox    │                 │ formularios clásicos   │
 │  ├─ reducers optimistas (pos/reducers)   │                 └──────────┬─────────────┘
 │  └─ service worker (public/sw.js)        │                            │
 └───────┬──────────────────────▲───────────┘                            │
         │ POST /tpv/api/push   │ GET /tpv/api/pull?since=               │
         ▼                      │                                        ▼
 ┌─────────────────────────────────────────────────────────────────────────────┐
 │ Laravel 13                                                                  │
 │  OperationProcessor → Handlers (Order, Cashier, Kitchen, Reservation, Time) │
 │  SnapshotBuilder (instantánea completa o incremental)                       │
 │  Servicios: TaxCalculator, Verifactu, Invoice, PrintService, ClockQr,       │
 │             TimeEntryRecorder, WorkedTime, CashSessionSummary, ImageProc.   │
 │  Evento TpvChanged ──► Reverb (WebSocket) ──► "hay cambios, haz pull"       │
 └───────────────────────────────┬─────────────────────────────────────────────┘
                                 ▼
                           PostgreSQL
```

## Offline-first

- **Instantánea**: `GET /tpv/api/bootstrap` devuelve todo lo que necesita el dispositivo (ajustes, personal con digest de PIN, zonas, mesas, carta, menús, pedidos abiertos, tickets de cocina, reservas de −1 a +30 días, estado de caja, turnos y fichajes propios). Se guarda en IndexedDB (`pos/db.ts`) y se carga en memoria (`pos/store.ts`) al arrancar, aunque no haya red.
- **Operaciones**: toda acción del TPV es una operación `{uuid, type, payload, createdAt, operatorId}` que:
  1. se aplica al momento en local con el reducer equivalente al del servidor (`pos/reducers.ts`),
  2. se guarda en la `outbox` de IndexedDB,
  3. se envía en lotes a `POST /tpv/api/push` cuando hay red (reintentos con *backoff*).
- **Idempotencia**: el servidor guarda cada operación en `client_operations` por UUID; reenviar la misma operación devuelve el resultado guardado sin repetir efectos.
- **Pull incremental**: `GET /tpv/api/pull?since=cursor` devuelve solo lo cambiado. Las operaciones pendientes se vuelven a aplicar encima de los datos del servidor, así que nada de lo hecho sin conexión se pierde de la pantalla.
- **Tiempo real**: el servidor emite `TpvChanged` (Reverb) con los ámbitos tocados; los clientes hacen *pull* al recibirlo. Si el WebSocket falla, hay polling periódico.
- **Rechazos**: si el servidor rechaza una operación (p. ej. `invalid_clock_qr`, `forbidden`), se marca como rechazada y el TPV muestra el motivo traducido.
- **Hora**: las operaciones llevan la hora del cliente (`createdAt`); el servidor la usa para tickets y fichajes si no está en el futuro, y marca `synced_late` si llega tarde.

### Tipos de operación

| Ámbito | Operaciones |
| --- | --- |
| Pedidos | `order.open`, `order.send`, `order.march`, `order.update`, `order.move`, `order.merge`, `order.requestBill`, `order.reopen`, `order.cancel`, `order.discount`, `line.void`, `line.discount`, `product.soldOut`, `print.job` |
| Caja | `cash.open`, `cash.close`, `ticket.issue` (solo en dispositivos de caja) |
| Cocina | `kds.status` |
| Reservas | `reservation.save`, `reservation.status` |
| Fichaje | `time.clock` |

Cualquier operación puede llevar `printJobs`: documentos de impresión ya maquetados en el cliente, que el servidor guarda en `print_jobs` y envía al driver.

## Dispositivos

- Cada aparato se registra en `/tpv` (cookie `tpv_device` con UUID + token, cuyo hash se guarda en `devices`). Tipos: `tablet`, `cashier`, `kds` y `clock`.
- Solo un administrador puede registrar cajas y pantallas de fichaje; cocina puede registrar KDS.
- Las cajas tienen su propia serie de tickets (`ticket_series`), numerada localmente en la caja para poder emitir sin conexión.

## Usuarios y sesiones

- Login con correo y contraseña (Fortify) o con QR personal (`/acceso/{token}`).
- En un dispositivo compartido, la sesión es del usuario que lo abrió; el **operario** activo se cambia con PIN (también offline) y viaja en cada operación como `operatorId`.
- Middleware: `EnsureUserIsActive`, `EnsurePasswordChanged`, `EnsureUserIsAdmin` y `SetLocale`.

## Dinero e impuestos

- Importes en **céntimos enteros**, precios con IVA incluido.
- `TaxCalculator` (PHP) y `computeTax` (`pos/money.ts`) son el mismo algoritmo: agrupa por tipo de IVA, reparte el suplemento de terraza proporcionalmente, la última base absorbe el redondeo y la base se calcula como `total / (1 + tipo)`, redondeando como PHP (`roundHalfAway`).
- El servidor recalcula los totales del ticket y audita (`ticket.total_mismatch`) si no coinciden con los del cliente.

## Tickets, Verifactu y facturas

- `ticket.issue` crea `tickets`, `ticket_lines` y `payments`, marca las líneas pagadas y cierra el pedido.
- `VerifactuService` encadena cada ticket con el hash del anterior y genera la URL del QR (entorno de pruebas de la AEAT, simulado).
- Cada ticket tiene un `public_token` (32 hex, generado en la caja) para la página pública `/factura/{token}`, donde el cliente pide la factura completa (`InvoiceService`: serie anual, PDF con DomPDF, correo opcional).

## Impresión

- Documento independiente del hardware (`PrintDocument`: líneas de texto, filas, separadores, QR, imagen, corte) maquetado en el cliente (`pos/print.ts`).
- `PrintService` → `PrinterDriver`: `SimulatedPrinterDriver` (guarda y marca impreso) y `EscPosPrinterDriver` (convierte a ESC/POS; el transporte por socket está por activar).
- Destinos de producción → impresoras y/o pantalla KDS.

## Fichaje (registro de jornada)

- `time_entries` es **solo inserción**: el modelo lanza excepción al actualizar/borrar y en PostgreSQL hay *triggers* `BEFORE UPDATE OR DELETE` y `BEFORE TRUNCATE`.
- Cadena de hash por trabajador: `hash = sha256(previous_hash | uuid | user | type | occurred_at | received_at | source | device)`. `TimeEntryRecorder::verifyChain()` la comprueba y el back office muestra el estado.
- Correcciones en `time_entry_corrections` (también inmutables): modificar, añadir o anular, con motivo, autor y hash. `WorkedTime` combina originales y correcciones para calcular jornadas, pausas, horas extra y avisos.
- QR dinámico: `código = ventana + "." + HMAC(secreto, "clock:" + ventana)[0..16]`, con ventana = `floor(segundos_unix / periodo)`. La pantalla lo calcula offline (`clockQrCode` en `pos/crypto.ts`) y el servidor (`ClockQrService`) acepta ±1 ventana respecto a la hora del fichaje.
- Exportaciones (`TimeReportExporter`: PDF, CSV y XLSX) y API de Inspección, todas auditadas.

## API externa (Sanctum)

- `/api/v1/reservations`: permisos `reservations:read` y `reservations:write`.
- `/api/inspeccion/v1/trabajadores` y `/registros`: permiso `inspection`.
- Los tokens se crean y revocan en Gestión → API.

## Frontend

- `resources/js/pages/admin/*`: back office (layout `AppLayout` + `AdminLayout`, componentes en `components/admin/*`).
- `resources/js/pages/pos/Shell.vue`: contenedor del TPV con enrutado por hash (`pos/router.ts`): `#/sala`, `#/taula/{id}`, `#/comanda/{uuid}`, `#/caixa`, `#/caixa/{uuid}`, `#/cuina`, `#/reserves`, `#/fitxar[/{codi}]`, `#/qr` y `#/assistent`.
- Asistente de IA (OpenRouter, `openai/gpt-5.6-luna`): `AssistantService` envía `docs/MANUAL.md` en el system prompt; historial en `assistant_conversations` / `assistant_messages`; streaming NDJSON en `POST /asistente/api/chat`.
- Vistas del TPV en `components/pos/views/*`; lógica en `resources/js/pos/*` (`store`, `db`, `sync`, `reducers`, `orders`, `cashier`, `print`, `catalog`, `money`, `crypto`, `notifications`).
- i18n propio (`resources/js/i18n/{ca,es}.ts`, tipado: el castellano debe tener las mismas claves que el catalán).
- Rutas tipadas con Wayfinder (`@/routes/...`).

## Calidad

- Tests Pest sobre SQLite (`php artisan test`), Larastan nivel 7, Pint, ESLint y vue-tsc.
