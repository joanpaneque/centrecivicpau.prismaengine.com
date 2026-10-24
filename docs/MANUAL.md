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

- Primero salen las **familias** (cuadros grandes del color de la familia, con su imagen al lado del nombre si tiene). Al tocar una familia salen sus productos; **← Famílies / Familias** vuelve atrás. Si la familia tiene subfamilias, salen como botones encima.
- Los productos son botones rellenos del color de su familia (o del suyo), con el texto en blanco o negro según lo que se lea mejor.
- Buscador siempre visible (los dos idiomas, **sin acentos**).
- Primer cuadro **Més demanats / Más pedidos**: los más vendidos primero.
- Tocar varias veces suma unidades; hay botón para restar.
- Toque largo: marcar **Esgotat / Agotado** (se desactiva en todos los dispositivos) o volver a disponible.
- Un producto puede no tener precio todavía (**Sense preu / Sin precio**). Se puede añadir y enviar igualmente; el precio se pone con el engranaje o al cobrar.
- **Inventa producte / Inventar producto:** nombre libre, precio con teclado en pantalla (se puede dejar vacío) y destino (cocina, barra o sin destino). Al añadir, si hay conexión, la IA propone el nombre bien escrito en catalán y castellano («croq. jamon» → «Croquetes de pernil / Croquetas de jamón»): se acepta o se deja lo escrito. Si se marca **Afegeix-lo també a la carta / Añadirlo también a la carta**, se elige la familia (la IA marca una **Proposta / Propuesta**) y el producto queda en la carta. Sin conexión se añade solo a la cuenta. El IVA es el de Ajustes.

### Modificadores, notas y pases

- Si el producto tiene opciones (leche, azúcar, licor del carajillo, marca de cerveza…), se abre un **asistente paso a paso**: una pantalla por grupo de opciones con cuadros grandes. Si solo se puede elegir una, pasa sola a la siguiente. **Salta / Saltar** deja ese grupo sin nada y **Afegeix ja / Añadir ya** añade el producto en cualquier momento. Al final se puede poner una **nota** y la cantidad. Algunas opciones son obligatorias.
- Tocar una línea sin enviar abre la edición completa (opciones, nota, pase, cantidad).
- **Nota** libre por línea (sin sal, poco hecho…).
- **Engranaje** en cada línea (enviada o no): cambia **precio y nombre**. Por defecto solo en esa cuenta; con **Desa-ho també a la carta / Guardarlo también en la carta** queda en el producto para siempre (viene marcado si el producto no tenía precio). Cualquier camarero puede usarlo.
- **Pas / Pase:** 1r / 2n / Postres. Se puede **Envia i retén el 2n / Enviar y retener el 2.º** y luego **Marxa 2n / Marcha 2.º** cuando toque.

### Menú del día

Si hay menús programados para hoy, en la cuadrícula de familias aparece la familia **Menús** (verde, con cuántos hay). Al abrirla salen los menús de hoy (del día, infantil, de grupo…) con su precio; al tocar uno se abre directamente para elegir los platos. El camarero elige primero → segundo → etc. según las secciones. Cada plato va a su destino (cocina o barra). Puede haber suplementos.

### Enviar

**Envia / Enviar.** Cada destino (cocina, barra…) recibe solo lo suyo. Confirmación «enviado a [impresora]». La impresión es **simulada** (se guarda una vista previa de ticket de 80 mm).

En **venta rápida desde la caja** también hay **Envia i cobra / Enviar y cobrar**: envía y abre directamente el cobro.

En la **caja** (mesas y ventas rápidas) hay además **Cobra sense enviar / Cobrar sin enviar**: añade los productos a la cuenta **sin enviar nada a cocina ni a barra** (no imprime ni sale en pantalla de cocina) y abre directamente el cobro. Para lo que se sirve en el momento desde la barra.

Las líneas iguales enviadas en rondas distintas (2 cañas y luego 1 más) se ven en la cuenta **juntas** (3 cañas) y salen juntas en la proforma y el tiquet. Cocina y barra siguen recibiendo solo lo nuevo de cada envío. Lo que ya está cobrado (cobro por productos o por partes) **no se muestra** en la cuenta.

### Cambiar, juntar, descuentos, anular

- **Mou taula / Mover mesa** (botón arriba en la cuenta): abre el plano; se puede cambiar de sala (terraza…) y tocar la mesa nueva. Pregunta «¿Seguro que quieres mover la mesa X a la mesa Y?» con **Aceptar / Cancelar**. Las mesas **ocupadas no se pueden elegir** (para juntar cuentas está **Junta**). Lo que no se haya enviado también se mueve.
- **Junta / Juntar:** fusiona con otra mesa que ya tenga cuenta.
- **Descompte / Descuento:** a una línea o a todo el cuenta (porcentaje o importe), con motivo.
- **Anul·la / Anular:** tocar la línea → anular. **No pide motivo** y cualquier camarero puede hacerlo. Si hay más de una unidad, se elige cuántas. Imprime la anulación en el destino que la preparaba (cocina o barra).
- **Demana el compte / Pedir la cuenta:** la mesa pasa a ámbar y aparece en caja como pendiente. En **caja**, además abre la **proforma** en PDF (no es factura). **Las tablets no cobran.**
- **Imprimeix la proforma / Imprimir la proforma** (solo caja): con el **programa de impresión** (ver sección 16) sale directamente por la impresora de tiquets; sin él, abre el PDF de la cuenta pendiente para imprimirlo con Windows.
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
   - Antes de cobrar, **Proforma** imprime la cuenta (no es factura): directa con el programa de impresión, o en PDF sin él.
   - Mientras no se haya emitido el tiquet, en **Cobrarlo todo** (y en **Partes iguales** antes de cobrar la primera parte) cada línea tiene **− / +** para corregir la cantidad; con una sola unidad, el botón de papelera la quita. No imprime nada en cocina ni barra. No se puede bajar de lo que ya está cobrado, y los menús del día no se pueden cambiar desde aquí.
   - Si la cuenta tiene productos **sin precio**, al entrar a cobrar sale **Falten preus / Faltan precios**: hay que poner el precio de cada uno (**Desa i continua / Guardar y seguir**) antes de poder cobrar. Si el producto no tenía precio en la carta, se guarda en la carta por defecto. **Cancelar** vuelve a la cuenta.
4. En los tres modos, **Ha pagat de més / Ha pagado de más** abre un teclado numérico en pantalla (no el del sistema) para marcar un importe superior al que toca. Forma de pago: **Efectiu** (entregado y cambio), **Targeta** o **Mixt**. Si el importe supera lo cobrado, abajo aparece **Sobren / Sobran X €**. En **tarjeta**, el extra es propina y el tiquet lleva la línea **X € de més — Propina / de más — Propina**. En **efectivo** esa diferencia es cambio y no sale como propina. No hay datáfono integrado: la tarjeta solo registra el importe.
5. Se emite el **tiquet** (factura simplificada). Sale un recuadro con el número y la vista previa.
   - Con el **programa de impresión** instalado (sección 16), el tiquet **sale solo por la impresora de tiquets** al cobrar; el recuadro lo confirma y tiene **Torna a imprimir / Volver a imprimir** por si hace falta otra copia.
   - Sin el programa, **Obre el PDF / Abrir PDF** abre el ticket en una pestaña nueva y desde ahí se imprime con el diálogo de Windows.
   - **Fet / Hecho** (o **Següent part / Siguiente parte** si queda cuenta) cierra el recuadro.

### Qué lleva el ticket

Logo, datos del emisor, serie y número, fecha y hora, mesa y camarero, líneas, suplemento de terraza, base, IVA desglosado, total, forma de pago, **QR tributario Verifactu (simulado, no se envía a Hacienda)** y **QR «Solicita tu factura»**.

La numeración es correlativa **por caja** (serie del dispositivo), para poder emitir sin conexión. Si el número ya existiera, el servidor lo corrige.

**Reimprimeix / Reimprimir:** tocar un tiquet de la sesión actual en esa caja → **Imprimeix / Imprimir** (directo con el programa de impresión) u **Obre el PDF / Abrir PDF** (sin él). Los antiguos se reimprimen desde Gestión (PDF).

**Informe X** y el **cierre Z** también salen en una vista previa: con el programa de impresión, **Imprimeix / Imprimir** los saca directamente por la impresora de tiquets; sin él, **Obre el PDF / Abrir PDF** y se imprime con el diálogo de Windows.

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

### Clientes habituales: resumen y una factura de todo junto

Para quien viene muchas veces, paga cada vez y al final quiere **un solo documento de todo**.

- **Crear un cliente:** en caja, pestaña **Clients / Clientes** → **Nou client / Nuevo cliente**. Código (lo elegís vosotros, por ejemplo «EMP1») y nombre; NIF, dirección, correo y teléfono son **opcionales**. También se puede crear desde el botón **Client / Cliente** de una cuenta.
- **Asignar una cuenta:** dentro de la mesa (o al cobrar) botón **Client / Cliente** (icono de persona; en la tablet, arriba en la barra azul) → buscar por código o nombre → elegirlo. El botón pasa a mostrar el código. Se cobra normal: el ticket queda a nombre del cliente. Se puede cambiar o quitar con el mismo botón.
- **Un ticket ya cobrado sin asignar:** **Clients** → el cliente → **Afegeix un tiquet / Añadir ticket** → buscar por número o mesa (últimos 90 días). La **X** de cada ticket lo quita del cliente.
- **Imprimir el resumen:** **Clients** → el cliente → salen sus **tickets pendientes** (todos marcados; se pueden desmarcar) → **Imprimeix el resum / Imprimir resumen**. Sale un ticket con todos sus tickets y líneas, el total, el IVA y un **QR**. **No es factura.** Los tickets incluidos ya no vuelven a salir como pendientes; los resúmenes se pueden **reimprimir**.
- **Factura de todo junto:** el cliente escanea el QR del resumen (`/factura/resum/…`) y rellena sus datos (vienen ya puestos si el cliente los tenía). Se emite **una sola factura completa** que sustituye a todos esos tickets. Después, ni el resumen ni ninguno de sus tickets se pueden volver a facturar (el QR de cada ticket muestra esa factura).
- Un ticket que ya tiene factura propia no entra en ningún resumen.

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

- Categorías (familias) en árbol, color, **imagen** (sale al lado del nombre en el TPV; PNG, JPG o WebP), destino de producción, reordenar arrastrando. **No se puede borrar** una categoría con productos o subcategorías (hay que moverlos antes).
- Producto: nombre ca/es, categoría, precio **con IVA incluido** (se puede dejar **vacío** = sin precio; se pondrá desde el TPV), tipo de IVA (por defecto 10 %), foto (recorte cuadrado), color, alérgenos, modificadores, destino, disponible sí/no, orden. Duplicar, reordenar, agotar.
- Grupos de modificadores: asignables a categoría o producto; obligatorios o de elección múltiple.

### Asistente IA (comandas por voz, informes, carta, menús y cartas)

**Assistent IA** (en el menú del TPV y, para administradores, en Gestión) es un solo asistente que se usa **hablando o escribiendo**. Lo que puede hacer depende de quién lo usa:

- **Todos:** resolver dudas de la aplicación (con el manual).
- **Sala y administradores, en el TPV — comandas por voz:** «posa dues canyes i un tallat a la taula 4», «un cafè amb llet sense lactosa per a la 7 i envia-ho». Añade a la mesa todo lo que entiende (queda **por enviar**, como si se hubiera tocado; con «envia-ho» lo envía a cocina y barra) y **pregunta con botones lo que no tiene claro** (por ejemplo «cervesa»: ¿canya, gerra o quinto?; una mesa que no existe; una opción obligatoria), sin dejar de poner lo demás. La tarjeta verde muestra qué ha puesto, lo que falta, y los botones **Obre la taula** y **Envia**.
- **Administradores — todo el negocio (solo lectura):** «com anem avui?», «vendes d'aquesta setmana per dia», «què s'ha venut més aquest mes?», «vendes per cambrer de l'agost», «busca el tiquet de l'entrecot d'ahir», «tancaments de caixa amb descuadre», «hores treballades de la Marta», «reserves de dissabte», «anul·lacions i descomptes d'aquesta setmana», «quant hem gastat en proveïdors». Sale una **tarjeta de informe** con las cifras clave, un **gráfico** (tocar una barra muestra su valor), la **tabla** con los datos exactos, botón **CSV** (abre en Excel) y enlaces a Gestión. Las cifras salen siempre de los datos, no las inventa la IA. **No puede modificar** tiquets, cobros, cierres ni fichajes.
- **Administradores — carta, menús y cartas:** familias, productos, precios, menús, cartas imprimibles, diseños e impresión (ver abajo).
- **Micrófono grande:** tocar para hablar, volver a tocar para enviar (la ✕ cancela). **Mientras hablas, el texto va apareciendo en directo** en el chat; al tocar otra vez se envía. Necesita `ELEVENLABS_API_KEY` en el `.env` y permiso de micrófono en el navegador (HTTPS). Si el directo fallara, se transcribe la grabación al terminar. El asistente de ayuda también tiene micrófono, y escribe lo dictado en la caja de texto.
- **Manual** (icono del libro) y **Aturar / Parar** mientras trabaja.
- Qué puede hacer: crear y cambiar **familias** (nombre, color, orden), **productos** (nombre en catalán y castellano, precio o sin precio, familia, posición, opciones, agotado) y **menús del día** completos (precio, qué incluye, días, secciones y platos con suplementos). También puede retirar productos o borrar menús, pero **siempre pregunta antes**.
- **Cartas imprimibles** (aparte de los menús): una carta de tapas, de bebidas, de postres, de vinos… con secciones, platos, una descripción corta opcional y el precio de cada uno. Ejemplos: «Fes-me una carta de tapes amb les braves, les croquetes i el pop a 12,90 €», «Afegeix el flam a la carta de postres», «Treu els calamars», «Puja el vermut a 3 €», «Posa-li el disseny de Nadal», «Imprimeix la carta de vins en anglès».
  - **Precios:** si no se le dice el precio de un plato, lo busca en la carta del TPV y usa el de ese producto (y si después cambia el precio en el TPV, la carta cambia también). Si el plato no está en el TPV, **pregunta el precio**; nunca se lo inventa.
  - Los cambios se piden de uno en uno (añadir, quitar, cambiar precio, mover, renombrar secciones…) sin repetir toda la carta. Quitar un plato que se le pide quitar lo hace directamente; borrar la carta entera, siempre pregunta antes.
  - **Precio con texto:** «el llamàntol a S.M.» pone «S.M.» (según mercado) o el texto que se diga en lugar del precio.
  - **Notas al pie:** «afegeix una nota a baix: S.M. = segons mercat» pone texto pequeño al final de la carta (leyenda, alérgenos…). Solo si se le pide.
  - **Control de la hoja:** «que hi càpiga en un full», «posa els preus en vermell», «fes la lletra dels plats més grossa»… Ajusta el máximo de hojas y el estilo (CSS) de la hoja impresa, la genera de verdad para comprobar cuántas hojas ocupa y lo corrige antes de contestar. Si ni con la letra más pequeña cabe, pregunta qué quitar.
- Ejemplos: «Crea el menú de dilluns a 13,50 € amb 3 primers i 3 segons», «Crea la família Tapes i posa-hi braves i croquetes», «Posa les croquetes les primeres», «Canvia el segon plat del menú per lluç».
- Lo que hace aparece en verde (✓) y sale una **tarjeta** en el chat: la familia con sus productos, o el **menú o la carta con su hoja A4 de verdad**, con los diseños, los idiomas y **Genera el PDF** ahí mismo. Se le puede pedir directamente: «imprimeix el menú en anglès i francès» y deja la tarjeta lista con esos idiomas marcados. **Obre a Gestió** abre la pantalla correspondiente.
- El chat se abre vacío, listo para hablar o escribir (no hay mensajes propuestos). Cuando el asistente necesita una respuesta cerrada (por ejemplo «Sí, esborra-ho» / «No», o canya / gerra) salen **botones grandes** para contestar con un toque. Se puede seguir pidiendo cambios sobre lo mismo en la misma conversación.
- Todo queda registrado en la **Auditoría** con el usuario que lo pidió. El historial de conversaciones está en el botón de la izquierda.

### Imprimir un menú o una carta (plantillas e idiomas)

Desde la tarjeta del menú o de la carta en el **Assistent IA** (dentro del chat), o desde **Gestión → Menús** / **Gestión → Cartes** (botón **Imprimeix / Imprimir**):

- **Diseño:** Normal ✨, Halloween 🎃, Nadal/Navidad 🎄, Pasqua/Pascua 🐣 o Infantil 🎈. El elegido queda guardado en el menú. También se le puede pedir a la IA («posa-li el disseny de Nadal»).
- **Idiomas:** español, catalán, francés, inglés, alemán, ruso, italiano y neerlandés. Al tocar un idioma marcado se ve en la vista previa; tocándolo otra vez se quita. **Tots / Todos** los marca todos.
- La **vista previa** muestra la hoja tal como saldrá. **Genera el PDF** crea un documento con **una hoja A4 por idioma**, con el logo; se abre en una pestaña nueva para imprimirlo.
- Cada idioma de un menú cabe siempre en una sola hoja: si el menú es largo, la letra se ajusta sola. Una carta corta también; una carta larga (por ejemplo de vinos) continúa en las hojas necesarias, con la misma letra en todas, y cada idioma empieza en hoja nueva.
- Las traducciones se hacen solas al guardar el menú (en segundo plano). Si al imprimir aún falta alguna, se hace en ese momento (tarda unos segundos). Si se cambia el menú, se vuelven a traducir.
- Puede haber tantos menús como se quiera (del día, infantil, de grupo, de Navidad…): cada uno con su precio, días y diseño.

---

### Cartas para imprimir (Gestión → Cartes)

Cartas para imprimir o enseñar al cliente (bebidas, tapas, postres, vinos…), aparte de los menús del día y de la carta del TPV. Cada carta tiene nombre, subtítulo opcional (horario, «per compartir»…) y secciones con platos. Cada plato puede ser un **producto del TPV** (si se deja el precio vacío usa el del producto y lo sigue si cambia) o un **plato libre** con su precio; además, una descripción corta opcional. En vez del precio se puede poner un **texto** (casilla **o text / o texto**), por ejemplo «S.M.» (según mercado). También: **Notas al pie** (texto pequeño al final de la última hoja), **Hojas** (Automático o un máximo, por ejemplo 1 para que quepa en una sola hoja; la letra se reduce lo necesario) y, en **Avanzado: estilo propio (CSS)**, el estilo extra de la hoja impresa (lo suele escribir el asistente). Botones: **Imprimeix**, **Edita**, **Duplica**. Lo más cómodo es crearlas y cambiarlas con el **Assistent IA**.

## 15. Menús del día (Gestión → Menús del día)

Editor sencillo: nombre, precio, secciones (primeros, segundos, postre, bebida… o las que se quiera), cuántas elecciones por sección, pase de cocina, platos de la carta o texto libre, suplementos.

Programación: siempre / días de la semana / fechas.

**Duplica / Duplicar** un menú (p. ej. el de ayer) y cambiar solo lo que toca.

En la tablet, el camarero recorre las secciones paso a paso.

---

## 16. Impresión y destinos (Gestión → Impresión)

Destinos de producción (cocina, barra…): modo **impresora**, **pantalla** o ambos.

Impresoras: nombre, tipo y destinos asignados. Marca una como **Imprimeix tiquets de caixa / Imprime tickets de caja**.

### Programa de impresión para Windows (impresión directa, sin diálogos)

El servidor está en Internet (en Alemania) y no puede llegar a las impresoras del bar. Para que todo salga **solo, al instante y sin diálogos ni PDF**, se instala el **programa de impresión** en el ordenador del bar que tiene las impresoras (conectadas por USB) o en cualquiera de la misma red (si son de red). Lo hace todo él:

- **No hay que abrir puertos del router ni poner IPs del servidor**: el programa se conecta él al servidor (como un navegador) y el servidor le avisa al instante (por la misma conexión en tiempo real que usa el TPV) cada vez que hay algo para imprimir. Si esa conexión fallara, igualmente mira cada pocos segundos.
- Imprime **tiquets de caja, proformas, comandas de cocina y barra, marchas, anulaciones, informes X/Z y pruebas**, con acentos y €, el logo, el QR de la factura y el corte de papel.
- Se instala para el usuario de Windows (sin ser administrador), **se abre solo al encender el ordenador** y queda en un icono junto al reloj. Cerrar la ventana no lo para. Se **actualiza solo** cuando hay versión nueva.
- Si el ordenador estaba apagado o sin Internet, al volver imprime lo pendiente de los **últimos 30 minutos** (lo anterior queda como «Caducat» en el registro, para no sacar comandas viejas).

**Instalarlo** (Gestión → Impressió, apartado **Programa d'impressió per a Windows**, donde están los mismos pasos):

1. En el ordenador del bar: **Descarrega el programa**, ábrelo y pulsa **Instal·la**. Si Windows avisa «Windows protegió su PC», pulsar **Más información → Ejecutar de todas formas**.
2. En Gestión → Impressió: **Afegeix ordinador**, ponerle nombre («PC barra») y **copiar la clave** que sale (solo se muestra esa vez; si se pierde, **Nova clau**).
3. En el programa: pegar la clave en **Clau de connexió** → **Connecta**. Debe salir en verde **Connectat i a punt d'imprimir**. El ordenador aparece en Gestión como **Connectat**, con la lista de impresoras de Windows que tiene.
4. Editar cada impresora en Gestión → Impressió: **Imprimeix a través de** = ese ordenador; **Impressora de Windows** (de la lista) o la IP si es de red; **Format**: **ESC/POS directe** (impresoras de tiquets: lo más rápido, corta el papel) o **Driver de Windows** (cualquier impresora, o si con ESC/POS sale en blanco o con símbolos raros); **Joc de caràcters** (si los acentos salen mal, probar el otro). Guardar y **Imprimeix una prova**.
5. Marcar la impresora de **tiquets de caja** y asignar las demás a los destinos (cocina, barra…).

En el propio programa se ve si está conectado, qué impresoras imprime, las **últimas impresiones** (con el error si alguna falla, que también sale como aviso junto al reloj) y un botón para **imprimir una prueba** en cualquier impresora del ordenador sin pasar por el servidor. **Obre el registre** abre el fichero con todo lo que ha hecho. Se desinstala desde el propio programa o desde Configuración de Windows → Aplicaciones.

Las **impresoras de red** conviene tenerlas con **IP fija** (reserva DHCP en el router), para que no cambie. Las **USB** tienen que estar instaladas en Windows con su driver (o el «Generic / Text Only») para que salgan en la lista.

### Tipos de impresora

- **Del PC (Windows)**: con el programa de impresión, imprime directamente en esa impresora de Windows. Sin programa, el ticket sale por el TPV de **caja** con el diálogo de imprimir del navegador (hay que dejar ese TPV abierto y elegir la impresora con el mismo nombre que en **Nom a Windows / Nombre en Windows**; la primera vez, papel de 80 mm y sin cabeceras ni pies).
- **ESC/POS en red**: IP y puerto (9100). Se asigna a un ordenador con el programa (que está en la misma red); el servidor por sí solo no llega a la red del bar.
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
