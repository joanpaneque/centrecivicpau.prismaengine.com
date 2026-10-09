# Manual de la aplicación — TPV Centre Cívic Pau

Manual de uso de la aplicación de TPV y gestión del bar-restaurante del Centre Cívic Pau (Carrer Sant Pere, 12, 17494 Pau, Girona). El asistente de IA se basa **solo** en este documento. Si algo no está aquí, la aplicación no lo hace o no está documentado.

La interfaz es bilingüe. Donde importa, se indica el texto del botón en **catalán / castellano**.

Última actualización: 7 de octubre de 2026.

---

## 1. Qué es esta aplicación

Hay **dos partes**:

- **TPV** (`/tpv`): tablets, caja, pantalla de cocina y pantalla de fichaje. Pensado para usar con el dedo, de pie y con prisa. Funciona **sin wifi** (guarda las acciones y las envía al reconectar).
- **Gestión** (`/panel` y `/gestion/...`): ordenador del administrador. Carta, usuarios, tickets, fichajes, turnos, facturas de proveedores, ajustes. Necesita conexión.

Colores de estado de mesa: **libre** (blanco), **ocupada** (azul `#00056a`), **cuenta pedida** (ámbar), **reservada** (violeta discontinuo), **platos listos** (aviso en sala).

---

## 2. Primer arranque

Mientras **no existe ningún usuario**, se entra con `joanpd0@gmail.com` / `1234`. La aplicación obliga a crear el primer administrador (nombre, correo nuevo y contraseña) en `/configuracion-inicial`. Esas credenciales por defecto **dejan de funcionar para siempre**.

Después, el administrador crea al resto del personal desde **Gestión → Usuarios**.

---

## 3. Roles

- **Administrador:** TPV completo, editor del plano, Gestión, caja (si el dispositivo es de caja), informes de fichaje, facturas de proveedores, API, auditoría.
- **Camarero / trabajador (`staff`):** TPV de sala, comandas, reservas, fichaje propio y ver sus turnos. **No** entra en Gestión ni edita el plano.
- **Cocina (`kitchen`):** pantalla de cocina (KDS) y fichaje. No ve sala ni reservas.

Cada acción queda asociada a la persona que la hizo (el **operario** de la tablet, no necesariamente quien abrió la sesión).

---

## 4. Entrar y cambiar de persona

Formas de entrar:

1. Correo y contraseña en `/login`.
2. **QR de acceso** personal: el administrador lo imprime desde Usuarios. Al escanearlo se abre `/acceso/{token}` y queda la sesión iniciada.

En una tablet compartida, el botón del nombre (arriba a la derecha) abre **Quién eres / Qui ets**. Se elige persona y se introduce el **PIN de 4 dígitos**. Funciona **sin conexión**. Quien no tiene PIN no puede cambiar a su usuario en esa tablet: hay que pedirle al administrador que se lo configure.

Idioma: menú ☰ → Català / Castellano. Cada usuario también tiene idioma en su ficha.

Cerrar sesión: menú ☰ → **Tanca la sessió / Cerrar la sesión**.

---

## 5. Dispositivos

La primera vez que se abre `/tpv` en un aparato, se registra el dispositivo y se elige el tipo:

- **Tablet:** sala, comandas, reservas, fichaje. **No cobra.**
- **Caja (`cashier`):** cobra, abre y cierra caja, tiene una **serie de tickets propia**. En el mismo ordenador (el de la barra, táctil) **también muestra el QR de fichaje** junto a la caja: el personal ficha con el móvil mientras se cobra. Hay un botón **QR fitxatge / QR fichaje** para verlo a pantalla completa. Solo un administrador puede registrar cajas.
- **Cocina (`kds`):** pantalla de comandas. La cocina también puede registrar este tipo.
- **Fichaje (`clock`):** solo QR a pantalla completa, sin caja. Úsalo si tienes una pantalla extra en la pared; **no hace falta** si el ordenador de caja ya muestra el QR.

Los dispositivos se gestionan en **Gestión → Dispositivos** (nombre, revocar). Si se pierde un aparato, hay que revocarlo.

Indicador de red (siempre visible en el TPV): en línea / sin conexión / operaciones pendientes de enviar.

---

## 6. Sala (TPV → Sala)

Pestañas grandes por zona arriba. Debajo, el **plano** o la **lista**.

Zonas de ejemplo (se pueden cambiar en Gestión): Terraza exterior, Porche, Comedor, Barra.

### Estados de una mesa

- Libre, ocupada (importe y tiempo desde que se abrió), cuenta pedida, reservada (hora), platos listos.

### Abrir una mesa

Tocar la mesa → se abre la comanda. Si está libre, se pide el número de comensales.

**Venta rápida / barra / para llevar:** botón **Venda ràpida / Venta rápida**. Crea un cuenta sin mesa.

Las cuentas rápidas abiertas aparecen como tiras encima del plano.

### Editor del plano (solo administrador, con conexión)

Botón **Edita el plànol / Editar el plano**.

- Tocar para seleccionar. Arrastrar para mover (encaja en cuadrícula).
- Tiradores de esquinas y lados para el tamaño. Las mesas redondas siguen redondas; paredes y ventanas se alargan.
- Tirador circular de arriba para girar (saltos de 15°). El texto de las mesas queda derecho.
- Barra del elemento: editar, girar, duplicar, traer al frente / enviar al fondo, borrar.
- Teclado: flechas mueven, Supr borra, Esc deselecciona.
- **Afegeix taula / Añadir mesa:** un toque crea una mesa cuadrada. La flechita del botón sirve para elegir redonda, rectangular, taburete o **taula auxiliar / mesa auxiliar**.
- **Afegeix element / Añadir elemento:** barra, pared, puerta (con arco de apertura), ventana, columna, planta, cocina, lavabos, escaleras, texto. Se puede cambiar texto, color, tamaño y giro.
- **Elimina les auxiliars lliures / Eliminar las auxiliares libres:** quita las auxiliares que no tienen cuenta abierta.
- Al soltar se guarda y el resto de tablets lo ven. Si falla, el elemento vuelve a su sitio.

Sin conexión el editor no se abre; el plano se sigue usando para comandas.

---

## 7. Comandas (tocar mesa → productos → enviar)

Objetivo: el mínimo de toques.

### Elegir productos

- Cuadrícula por categorías, con foto o nombre sobre color.
- Buscador siempre visible (los dos idiomas, **sin acentos**).
- Pestaña **Més demanats / Más pedidos**: los más vendidos primero.
- Tocar varias veces suma unidades; hay botón para restar.
- Toque largo: marcar **Esgotat / Agotado** (se desactiva en todos los dispositivos) o volver a disponible.
- **Inventa producte / Inventar producto:** nombre libre, precio con teclado en pantalla y destino (cocina, barra o sin destino, si no hay que imprimir). El IVA es el de Ajustes. No entra en la carta.

### Modificadores, notas y pases

- Si el producto tiene opciones (descafeinado, leche de avena, punto de la carne…), salen al añadirlo. Algunas son obligatorias.
- **Nota** libre por línea (sin sal, poco hecho…).
- **Pas / Pase:** 1r / 2n / Postres. Se puede **Envia i retén el 2n / Enviar y retener el 2.º** y luego **Marxa 2n / Marcha 2.º** cuando toque.

### Menú del día

Si hay un menú programado para hoy, aparece **Menú del dia**. El camarero elige primero → segundo → etc. según las secciones. Cada plato va a su destino (cocina o barra). Puede haber suplementos.

### Enviar

**Envia / Enviar.** Cada destino (cocina, barra…) recibe solo lo suyo. Confirmación «enviado a [impresora]». La impresión es **simulada** (se guarda una vista previa de ticket de 80 mm).

### Cambiar, juntar, descuentos, anular

- **Mou / Mover:** pasa el cuenta a otra mesa.
- **Junta / Juntar:** fusiona con otra mesa que ya tenga cuenta.
- **Descompte / Descuento:** a una línea o a todo el cuenta (porcentaje o importe), con motivo.
- **Anul·la / Anular:** línea o cantidad, con motivo. Imprime anulación en cocina.
- **Demana el compte / Pedir la cuenta:** la mesa pasa a ámbar y aparece en caja como pendiente. **Las tablets no cobran.**
- **Tanca el compte buit / Cerrar el cuenta vacío:** si no hay líneas.

Alérgenos (los 14 de la UE) visibles en cada producto.

Suplemento de terraza: si la zona lo tiene, se aplica un % configurable y sale como línea «Suplement terrassa X%» en el ticket.

---

## 8. Cocina / KDS (TPV → Cuina)

Tarjetas por pedido, por orden de llegada: mesa, tiempo (cambia de color a los 10 y 20 min), destí.

Estados: **Pendent → Preparant → Llest → Servit** (Pendiente → Preparando → Listo → Servido). Un toque cambia el estado. **Desfés / Deshacer** y **Servits recentment / Servidos recientemente** para recuperar.

Cuando un plato está **Llest / Listo**, la sala recibe un aviso (y un sonido si está activado en el menú ☰).

Las líneas **Retingut / Retenido** esperan a que sala pulse **Marxa**.

Filtro por destino. El rol cocina entra directo a esta pantalla.

---

## 9. Caja (solo dispositivo tipo Caja)

Registrar el aparato como **Caja**. Si no lo es, sale el aviso «Este dispositivo no es una caja».

### Abrir y cobrar

1. **Obre la caixa / Abrir la caja** con el fondo inicial.
2. Lista de cuentas abiertos (mesas y ventas rápidas).
3. **Cobra / Cobrar** un cuenta:
   - **Cobra-ho tot / Cobrarlo todo**
   - **Divideix per productes / Dividir por productos**
   - **Divideix a parts iguals / Dividir a partes iguales** (cada parte genera un ticket; el progreso vive en esa caja). Si alguien paga más que su parte, el extra se resta de lo que queda.
4. En los tres modos, **Ha pagat de més / Ha pagado de más** abre un teclado numérico en pantalla (no el del sistema) para marcar un importe superior al que toca. Forma de pago: **Efectiu** (entregado y cambio), **Targeta** o **Mixt**. Si el importe supera lo cobrado, abajo aparece **Sobren / Sobran X €**. En **tarjeta**, el extra es propina y el tiquet lleva la línea **X € de més — Propina / de más — Propina**. En **efectivo** esa diferencia es cambio y no sale como propina. No hay datáfono integrado: la tarjeta solo registra el importe.
5. Se emite el **tiquet** (factura simplificada) y se imprime.

### Qué lleva el ticket

Logo, datos del emisor, serie y número, fecha y hora, mesa y camarero, líneas, suplemento de terraza, base, IVA desglosado, total, forma de pago, **QR tributario Verifactu (simulado, no se envía a Hacienda)** y **QR «Solicita tu factura»**.

La numeración es correlativa **por caja** (serie del dispositivo), para poder emitir sin conexión. Si el número ya existiera, el servidor lo corrige.

**Reimprimeix / Reimprimir:** tickets de la sesión actual en esa caja. Los antiguos se reimprimen desde Gestión (PDF).

**Informe X:** parcial sin cerrar. **Tanca la caixa (Z) / Cerrar la caja (Z):** recuento por billetes y monedas, esperado, diferencia, totales por forma de pago, IVA, descuentos, suplemento y anulaciones. El Z definitivo lo calcula el servidor.

---

## 10. Factura completa a petición del cliente

El cliente escanea el QR del ticket (móvil, **sin login**) en `/factura/{token}`:

1. Nombre o razón social, NIF, dirección, correo.
2. Se genera la factura completa (PDF y, si hay correo, se envía).
3. Un ticket **no se factura dos veces**.

Si el ticket aún no ha llegado al servidor (se cobró sin wifi), la página dice que lo intente más tarde.

El administrador ve las facturas en **Gestión → Facturas** (PDF, reenviar). También puede emitirla desde el detalle del ticket.

Serie de facturas completas: una para todo el local, por año (`F2026-000001`…).

---

## 11. Reservas (TPV → Reserves)

Agenda por día. **Nova reserva / Nueva reserva:** nombre, teléfono, personas, fecha y hora, duración, zona o mesa (opcional), notas.

Estados: **Confirmada, Asseguts / Sentados, Acabada / Acabada, Cancel·lada / Cancelada, No presentat / No presentado**.

**Fes seure / Hacer sentar:** elige mesa y abre la comanda vinculada a la reserva. En el plano, la mesa se ve reservada a partir del margen de tiempo configurado.

No hay reservas desde una web pública. Sí hay **API** (ver apartado 20) para una web futura. No hay control automático de aforo.

---

## 12. Fichaje (TPV → Fitxar)

Cumple el art. 34.9 del Estatuto de los Trabajadores. Los registros **no se modifican ni se borran**. Una corrección la hace el administrador y queda aparte (motivo obligatorio).

### Cómo fichar

1. En el móvil: **Fitxar** → **Fitxa amb el QR del local / Fichar con el QR del local** (cámara). El QR está en el **ordenador de caja** (panel al lado de las cuentas) o en una pantalla de tipo Fichaje, y **cambia cada 30–60 s** (no vale una foto de casa). En caja, **QR fitxatge / QR fichaje** lo pone a pantalla completa.
2. En caja y cocina (dispositivos fijos) también se puede fichar **sin QR** desde la pestaña Fitxar (queda como origen `app`).

Acciones: **Entrada, Sortida / Salida, Pausa, Torna de la pausa / Vuelve de la pausa**.

Funciona offline: se guarda con la hora del móvil y se marca «sincronizado más tarde».

Cada persona ve su historial del día, horas de hoy, próximo turno y **Els meus torns / Mis turnos**.

Pantalla de QR a tamaño completo: `/tpv#/qr` («Fitxa aquí / Ficha aquí»). En el ordenador de caja se llega desde el panel del QR o la pestaña **QR fitxatge**.

**Conservación mínima 4 años.** Sin geolocalización ni biometría.

---

## 13. Planificación de turnos (Gestión → Planificación de turnos)

Calendario semanal y mensual por trabajador. Plantillas (mañana, tarde, partido…). Arrastrar para mover; Ctrl+arrastrar copia. En táctil el arrastre del navegador a veces falla: se puede tocar el turno o la celda y usar el diálogo.

**Copia la semana anterior / Copiar la semana anterior.**

Cada trabajador ve sus turnos en el TPV (Fitxar). El panel compara horas planificadas y fichadas.

---

## 14. Carta y categorías (Gestión → Carta)

Solo administrador.

- Categorías en árbol, color, destino de producción, reordenar arrastrando. **No se puede borrar** una categoría con productos o subcategorías (hay que moverlos antes).
- Producto: nombre ca/es, categoría, precio **con IVA incluido**, tipo de IVA (por defecto 10 %), foto (recorte cuadrado), color, alérgenos, modificadores, destino, disponible sí/no, orden. Duplicar, reordenar, agotar.
- Grupos de modificadores: asignables a categoría o producto; obligatorios o de elección múltiple.

---

## 15. Menús del día (Gestión → Menús del día)

Editor sencillo: nombre, precio, secciones (primeros, segundos, postre, bebida… o las que se quiera), cuántas elecciones por sección, pase de cocina, platos de la carta o texto libre, suplementos.

Programación: siempre / días de la semana / fechas.

**Duplica / Duplicar** un menú (p. ej. el de ayer) y cambiar solo lo que toca.

En la tablet, el camarero recorre las secciones paso a paso.

---

## 16. Impresión y destinos (Gestión → Impresión)

Destinos de producción (cocina, barra…): modo **impresora**, **pantalla** o ambos.

Impresoras: nombre, tipo y destinos asignados. Marca una como **Imprimeix tiquets de caixa / Imprime tickets de caja**.

Tipos:

- **Del PC (Windows)** (el habitual): el ticket sale por el TPV de **caja**, en el PC donde ya están los drivers. Deja ese TPV abierto. Aparece el diálogo de imprimir de Windows: elige la impresora con el mismo nombre que en Gestión (campo **Nom a Windows / Nombre en Windows**). La primera vez, papel de 80 mm y sin cabeceras ni pies del navegador. El Chrome no puede elegir solo la impresora: hay que seleccionarla en el diálogo (luego Windows suele recordar la última).
- **ESC/POS en red**: IP y puerto (9100). Solo si el servidor puede alcanzar esa IP (en la nube del local, no).
- **Simulada**: no sale papel; queda en el registro.

Botón **Imprimeix una prova / Imprimir una prueba**. El historial está en **Registre d'impressions / Registro de impresiones**.

---

## 17. Usuarios (Gestión → Usuarios)

Alta: nombre, idioma, rol, correo opcional, PIN de 4 dígitos, DNI/NIE.

- QR de acceso (imprimir, regenerar).
- Restablecer contraseña (temporal; obliga a cambiarla).
- Activar / desactivar.
- No puedes dejarte sin administradores (no te quites el rol admin a ti mismo si eres el último).

---

## 18. Registro horario del administrador (Gestión → Registro horario)

Listado por trabajador y fechas, estado de la cadena de hash, horas, extras, avisos (falta la salida, fichaje fuera de turno).

**Correcciones** (modificar, añadir, anular) con motivo, autor y hash. El original no se toca.

**Exportar** PDF, CSV y XLSX para la Inspección (datos de empresa y trabajador). Cada exportación se audita.

Aviso de salida: si no ha fichado la salida X minutos (120 por defecto, configurable) después del fin de turno, aparece en el panel.

Una jornada que cruza medianoche cuenta en el día en que empezó.

---

## 19. Facturas de proveedores (Gestión → Facturas de proveedores)

Pensado también para el móvil. **Escanear con la cámara** (recorte por esquinas, rotación, mejora) o **subir** imagen/PDF. Campos opcionales: proveedor, fecha, importe, notas. Listado con filtros y visor.

**No hay OCR ni contabilidad.** Solo se archivan.

---

## 20. Configuración (Gestión → Ajustes)

- Datos del negocio y logo.
- Emisor de tickets/facturas (nombre, NIF, dirección, pie del ticket).
- % suplemento de terraza, IVA por defecto, idioma por defecto.
- Serie de facturas completas.
- **Regenerar secreto del QR de fichaje:** invalida todos los QR actuales (hacerlo si se pierde una pantalla de fichaje).

**Panel (`/panel`):** ventas de hoy, tickets, mesas abiertas, reservas, quién está trabajando, avisos de fichaje, facturas de proveedor pendientes, gráfico de 7 días. Botón **Obre el TPV / Abrir el TPV**.

**Auditoría:** registro de acciones sensibles.

**Acceso API:** crear y revocar tokens Sanctum.

- Reservas: `/api/v1/reservations` con permisos `reservations:read` y `reservations:write`.
- Inspección: `/api/inspeccion/v1/trabajadores` y `/registros` con permiso `inspection`. Cada consulta se audita.

---

## 21. Tickets y cierres en Gestión

- **Tiquets:** listado, detalle, PDF, emitir factura completa.
- **Factures / Facturas:** PDF y reenviar por correo.
- **Tancaments de caixa / Cierres de caja:** histórico e informe Z en PDF.

---

## 22. Sin conexión (tablets y caja)

Al tener red, el dispositivo descarga carta, mesas, usuarios, reservas, caja, etc. y lo guarda en el aparato.

Sin red se sigue tomando comandas, cobrando (en caja), fichando y cambiando de operario. Las acciones se encolan con un identificador único (no se duplican al reintentar). Al volver la red se envían y se actualiza lo que haya cambiado en otros aparatos. Si el servidor rechaza algo, el TPV muestra el motivo.

La **primera carga** de un aparato nuevo sí necesita red. El asistente de IA **también necesita internet**.

Tiempo real con conexión: los cambios (mesas, comandas, cocina) llegan por aviso al resto de dispositivos.

---

## 23. Asistente de IA

- En **Gestión:** menú **Assistent IA / Asistente IA** (`/gestion/asistente`).
- En el **TPV:** pestaña **Assistent IA / Asistente IA** (cualquier persona conectada).

El modelo es GPT-5.6 Luna vía OpenRouter. Hay que tener `OPENROUTER_API_KEY` en el `.env` del servidor.

Funciones: historial de conversaciones (por usuario), nueva conversación, renombrar, borrar, copiar respuesta, ver este manual, detener la respuesta. Las conversaciones de una persona no las ve otra.

Responde cómo usar la aplicación. **No consulta ventas, comandas ni fichajes reales:** indica dónde verlos. Si se pide algo que el rol no permite, dice que lo haga un administrador.

---

## 24. Lo que esta aplicación no hace (de momento)

- Informes de ventas avanzados.
- Impresoras térmicas reales (solo simulación) y datáfono real.
- Envío real a Hacienda / Verifactu (el QR es de pruebas).
- Reservas desde una web pública (la API sí está).
- OCR o asiento contable de facturas de proveedor.
- Propinas.
- Control de aforo automático.

---

## 25. Atajos de dónde está cada cosa

| Quiero… | Dónde |
| --- | --- |
| Tomar una comanda | TPV → Sala → mesa |
| Cobrar | TPV en dispositivo Caja → Caixa |
| Ver cocina | TPV → Cuina |
| Hacer una reserva | TPV → Reserves |
| Fichar | TPV → Fitxar (+ QR del local) |
| Editar el plano | TPV → Sala → Edita el plànol (admin, online) |
| Cambiar la carta | Gestión → Carta |
| Menú del día | Gestión → Menús del día |
| Alta de personal | Gestión → Usuarios |
| Corregir un fichaje | Gestión → Registro horario |
| Cierres y tickets | Gestión → Tiquets / Tancaments |
| Factura de un proveedor | Gestión → Facturas de proveedores |
| Logo y datos fiscales | Gestión → Ajustes |
| Configurar impresoras | Gestión → Impresión |
| Imprimir tickets reales | TPV de caja abierto en el PC con los drivers |
| Preguntar dudas | Asistente IA (Gestión o TPV) |
