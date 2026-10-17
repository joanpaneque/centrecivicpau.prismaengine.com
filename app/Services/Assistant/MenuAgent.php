<?php

namespace App\Services\Assistant;

use App\Events\TpvChanged;
use App\Models\User;
use App\Services\Catalog\CartaWriter;
use App\Services\Catalog\SetMenuWriter;
use App\Services\TimeTracking\WorkedTime;

/**
 * The AI assistant: a tool-using loop that answers questions about the app and, depending
 * on who asks and from where, takes orders on the POS (everyone except kitchen), manages
 * the catalog, the set menus and the printable cartas, and reads reports of the whole business (administrators). Each
 * executed step is reported live through $emit; the turn ends with a reply, the cards to
 * show and, optionally, big answer buttons.
 */
class MenuAgent
{
    public const MAX_STEPS = 12;

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly SetMenuWriter $writer,
        private readonly CartaWriter $cartaWriter,
        private readonly WorkedTime $worked,
    ) {}

    /**
     * What this user can do from here: catalog/menus and reports for administrators, taking
     * orders on the POS for anyone who serves tables.
     *
     * @return array{catalog: bool, reports: bool, orders: bool}
     */
    public function abilities(User $user, string $context): array
    {
        return [
            'catalog' => $user->isAdmin(),
            'reports' => $user->isAdmin(),
            'orders' => $context === 'tpv' && ! $user->isKitchen(),
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  ending with the new user message
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{content: string, meta: array<string, mixed>, usage: array{prompt_tokens: int, completion_tokens: int}}
     */
    public function run(User $user, string $locale, array $history, callable $emit, string $context = 'admin'): array
    {
        $can = $this->abilities($user, $context);
        $tools = new MenuTools($user, $this->writer);
        $cartas = new CartaTools($user, $this->cartaWriter);
        $business = new BusinessTools($locale === 'es' ? 'es' : 'ca', $this->worked);
        $orders = new OrderTools;
        $allowed = [MenuTools::ASK => 'menu'];

        if ($can['catalog'] || $can['orders']) {
            $allowed['get_catalog'] = 'menu';
        }

        if ($can['catalog']) {
            foreach (MenuTools::definitions() as $definition) {
                $allowed[$definition['function']['name']] = 'menu';
            }

            $allowed += array_fill_keys(CartaTools::toolNames(), 'carta');
        }

        if ($can['reports']) {
            $allowed += array_fill_keys(BusinessTools::names(), 'business');
        }

        if ($can['orders']) {
            $allowed += array_fill_keys(OrderTools::names(), 'orders');
        }

        $definitions = array_values(array_filter(
            [...MenuTools::definitions(), ...CartaTools::definitions(), ...BusinessTools::definitions(), ...OrderTools::definitions()],
            fn (array $definition) => isset($allowed[$definition['function']['name']]),
        ));
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($user, $locale, $can)], ...$history];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $actions = [];
        $previews = [];
        $options = [];
        $question = '';
        $text = '';

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $reply = $this->assistant->complete($messages, $definitions);
            $usage['prompt_tokens'] += $reply['usage']['prompt_tokens'];
            $usage['completion_tokens'] += $reply['usage']['completion_tokens'];

            $message = $reply['message'];
            $content = is_string($message['content'] ?? null) ? trim($message['content']) : '';
            $calls = array_values(array_filter((array) ($message['tool_calls'] ?? []), 'is_array'));

            if ($content !== '') {
                $text = $content;
            }

            if ($calls === []) {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls];
            $asked = false;

            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $args = is_array($args) ? $args : [];

                if ($name === MenuTools::ASK) {
                    $asked = true;
                    $question = is_string($args['question'] ?? null) ? trim($args['question']) : '';
                    $options = array_values(array_slice(array_filter(array_map(
                        fn ($option) => is_string($option) ? mb_substr(trim($option), 0, 60) : '',
                        (array) ($args['options'] ?? []),
                    )), 0, 6));
                    $result = ['ok' => true];
                } else {
                    $status = match ($allowed[$name] ?? null) {
                        'business' => 'report',
                        'orders' => $name === 'get_floor' ? 'floor' : null,
                        'carta' => $name === 'get_cartas' ? 'cartas' : null,
                        default => MenuTools::status($name),
                    };

                    if ($status !== null) {
                        $emit(['type' => 'status', 'status' => $status]);
                    }

                    $outcome = match ($allowed[$name] ?? null) {
                        'menu' => $tools->run($name, $args),
                        'carta' => $cartas->run($name, $args),
                        'business' => $business->run($name, $args),
                        'orders' => $orders->run($name, $args),
                        default => ['result' => ['error' => 'Esta herramienta no está disponible para este usuario.']],
                    };
                    $result = $outcome['result'];

                    if (isset($outcome['action'])) {
                        $actions[] = $outcome['action'];
                        $emit(['type' => 'action', 'action' => $outcome['action']]);
                    }

                    if (isset($outcome['preview'])) {
                        $previews[$outcome['preview']['type'].':'.$outcome['preview']['id']] = $outcome['preview'];
                    }
                }

                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) ($call['id'] ?? ''), 'content' => (string) json_encode($result, JSON_UNESCAPED_UNICODE)];
            }

            if ($asked) {
                break;
            }
        }

        if ($tools->changed()) {
            TpvChanged::notify(['catalog']);
        }

        if ($question !== '' && ! str_contains($text, $question)) {
            $text = trim($text."\n\n".$question);
        }

        if ($text === '') {
            $text = $actions !== [] ? ($locale === 'es' ? 'Hecho.' : 'Fet.') : '…';
        }

        return [
            'content' => $text,
            'meta' => array_filter([
                'actions' => $actions,
                'previews' => array_values($previews),
                'options' => $options,
            ]),
            'usage' => $usage,
        ];
    }

    /**
     * @param  array{catalog: bool, reports: bool, orders: bool}|null  $can
     */
    public function systemPrompt(User $user, string $locale, ?array $can = null): string
    {
        $can ??= ['catalog' => $user->isAdmin(), 'reports' => $user->isAdmin(), 'orders' => false];
        $language = $locale === 'es' ? 'castellano' : 'catalán';
        $clock = now();
        $clock->locale($locale);
        $today = $clock->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm');
        $role = $user->isAdmin() ? 'administrador/a' : ($user->isKitchen() ? 'cocina' : 'personal de sala');
        $abilities = ['- Respondes dudas sobre cómo usar la aplicación, basándote SOLO en el manual de abajo (si algo no está, dilo y sugiere hablar con el administrador).'];

        if ($can['orders']) {
            $abilities[] = <<<'TEXT'
            - TOMAS COMANDAS en el TPV: «posa dues canyes a la taula 4», «un tallat amb llet de civada i un cafè sol per a la 7».
              · Usa get_catalog para los ids de producto y sus grupos de opciones, y get_floor si dudas de la mesa.
              · Añade con add_to_order TODO lo que esté claro (una llamada por mesa). Lo que no esté claro (producto ambiguo como «cervesa» cuando hay caña, jarra y quinto; mesa que no existe; una opción obligatoria sin decir) NO lo inventes: añade lo demás igualmente y pregunta lo que falta con ask_user, con opciones concretas (por ejemplo «Canya», «Gerra», «Quinto»).
              · Si nombran opciones (sin lactosa, sacarina, Estrella…) pásalas en option_ids. Notas libres («poc fet», «sense ceba») en note.
              · Por defecto las líneas quedan por enviar en la mesa (send=false). Usa send=true solo si dicen «envia-ho», «marxa», «a cuina ja».
              · Cuando esté hecho, di en una frase qué has puesto y en qué mesa. Si algo falta, dilo claro.
            TEXT;
        }

        if ($can['catalog']) {
            $abilities[] = <<<'TEXT'
            - GESTIONAS LA CARTA Y LOS MENÚS como un "vibe coding": familias y productos (nombre, precio, posición, familia, agotado, opciones), menús (del día, infantil, de grupo, de Navidad…), su diseño y su impresión en varios idiomas.
              · Antes de cambiar nada, lee lo que hay (get_catalog o get_set_menus). Nunca inventes ids.
              · Si el encargo está claro, hazlo directamente. Si falta algo importante (familia, días, precio del menú), pregunta con ask_user.
              · Borrar o retirar algo: SIEMPRE pregunta antes con ask_user («Sí, esborra-ho» / «No») y solo entonces llama con confirmed=true.
              · Nombres siempre en catalán y castellano, bien escritos, con mayúscula inicial. Precios en euros con IVA; sin precio = null. Posiciones desde 1.
              · Menús: secciones Primers (course 1), Segons (course 2), Postres (course 3); choices = cuántos platos elige el cliente. Si el plato existe en la carta usa su product_id. Para cambiar un menú, mándalo entero con su id.
              · Diseños: classic, halloween, christmas, easter, kids (infantil). Al guardar un menú se traduce solo a francés, inglés, alemán, ruso, italiano y neerlandés. Si piden imprimirlo o verlo en otros idiomas, usa print_set_menu: en el chat sale la hoja A4 con el botón para generar el PDF (una hoja por idioma).
            - CARTAS IMPRIMIBLES (cosa distinta de los menús y de la carta del TPV): una carta de bebidas, de tapas, de postres, de vinos… con secciones y platos, cada uno con su precio y, si quieren, una descripción corta. Mismos diseños, idiomas e impresión que los menús.
              · Si dicen «carta» refiriéndose a un documento para imprimir o enseñar al cliente, es esto (save_carta, edit_carta, print_carta). Si dicen «posa'l a la carta» refiriéndose al TPV (para cobrarlo), son productos (create_product).
              · PRECIOS: si el usuario dice el precio, pásalo en price. Si NO lo dice, mira get_catalog: si el plato está en el TPV pasa su product_id y NO pongas price (se usa el del TPV y lo sigue si cambia). Si no está en el TPV, NO inventes el precio: pregúntalo con ask_user (sin opciones). La herramienta rechaza los platos sin precio y te dice cuáles faltan.
              · Para cambios sueltos («afegeix les braves a 5 €», «treu el flam», «puja el vermut a 3 €», «canvia el disseny a Nadal») usa edit_carta con la lista de cambios; no reescribas la carta entera. Lee antes los ids con get_cartas. Quitar un plato o sección que piden quitar no necesita confirmación; borrar la carta entera sí.
            TEXT;
        }

        if ($can['reports']) {
            $abilities[] = <<<'TEXT'
            - VES TODO EL NEGOCIO (solo lectura, no puedes cambiar tiquets, cobros, cierres ni fichajes): estado en directo (live_status), informes de ventas agrupados como pidan (sales_report: por día, semana, mes, hora, día de la semana, producto, familia, camarero, forma de pago, mesa o zona), buscar tiquets y ver su detalle, cierres de caja y descuadres, horas trabajadas, reservas, anulaciones y descuentos, gasto en proveedores.
              · Las cifras SIEMPRE de las herramientas; nunca las inventes ni las calcules de memoria. Para comparar periodos, llama a la herramienta para cada periodo.
              · La tarjeta del informe (con gráfico y tabla) sale sola: en el texto comenta solo lo importante en 1-3 frases (lo que más se vende, el día flojo, un descuadre…), sin repetir la tabla.
              · Interpreta las fechas con sentido: «ahir», «aquesta setmana» (lunes a hoy), «el mes passat», «l'agost»…
            TEXT;
        }

        $abilities = implode("\n", $abilities);

        return <<<PROMPT
        Eres el asistente de IA del bar-restaurante del Centre Cívic Pau. Hablas con {$user->name} ({$role}), desde el TPV o el móvil, a menudo por voz: el texto puede venir con errores de dictado, interprétalo con sentido común (por ejemplo «canyes» = cañas, «la cuatro» = mesa 4).

        Qué haces:
        {$abilities}

        Reglas generales:
        - Si una petición tiene varias partes, haz todas las que se entiendan y pregunta solo por lo que falte.
        - Las tarjetas (comanda, familia, menú, carta, informe) salen solas en el chat: no repitas su contenido en el texto. Responde corto.
        - Usa ask_user SOLO cuando falte información o para confirmar algo delicado. Si la respuesta es cerrada (Sí/No, caña o jarra…) pon opciones cortas (máximo 6 palabras) como las diría el usuario; si es abierta (un precio, un nombre) no pongas opciones. Al terminar un encargo NO ofrezcas siguientes pasos ni sugerencias: di lo que has hecho y para.
        - Si piden algo que no puedes hacer, dilo y explica dónde se hace en la aplicación.
        - Responde en el idioma del usuario (por defecto {$language}). Sin tecnicismos ni ids en el texto.

        Ahora es {$today}.

        ===== MANUAL DE LA APLICACIÓN =====
        {$this->assistant->manual()}
        ===== FIN DEL MANUAL =====
        PROMPT;
    }
}
