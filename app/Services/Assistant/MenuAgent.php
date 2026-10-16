<?php

namespace App\Services\Assistant;

use App\Events\TpvChanged;
use App\Models\User;
use App\Services\Catalog\SetMenuWriter;

/**
 * "Carta amb IA": a tool-using loop where the model reads and changes the catalog and the
 * set menus on behalf of an administrator, like vibe coding but for menus. Each executed
 * change is reported live through $emit; the turn ends with a reply, the cards to preview
 * and, optionally, big answer buttons.
 */
class MenuAgent
{
    public const MAX_STEPS = 12;

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly SetMenuWriter $writer,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  ending with the new user message
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{content: string, meta: array<string, mixed>, usage: array{prompt_tokens: int, completion_tokens: int}}
     */
    public function run(User $user, string $locale, array $history, callable $emit): array
    {
        $tools = new MenuTools($user, $this->writer);
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($user, $locale)], ...$history];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $actions = [];
        $previews = [];
        $options = [];
        $question = '';
        $text = '';

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $reply = $this->assistant->complete($messages, MenuTools::definitions());
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
                    if ($status = MenuTools::status($name)) {
                        $emit(['type' => 'status', 'status' => $status]);
                    }

                    $outcome = $tools->run($name, $args);
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

    public function systemPrompt(User $user, string $locale): string
    {
        $language = $locale === 'es' ? 'castellano' : 'catalán';
        $clock = now();
        $clock->locale($locale);
        $today = $clock->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm');

        return <<<PROMPT
        Eres el asistente de IA del bar-restaurante del Centre Cívic Pau. Hablas con {$user->name}, administrador/a, desde el TPV o el móvil, a menudo por voz (el texto puede venir con errores de dictado: interprétalo con sentido común).

        Haces dos cosas:
        1. Respondes dudas sobre cómo usar la aplicación, basándote SOLO en el manual de abajo (si algo no está, dilo).
        2. Gestionas la carta y los menús con herramientas, como un "vibe coding" de cartas: el usuario pide, tú lo haces, se lo enseñas en el chat y puede seguir pidiendo cambios sobre lo mismo.

        Qué puedes hacer con herramientas: leer la carta (familias, productos, precios, grupos de opciones), crear y cambiar familias y productos (nombre, precio, posición, familia, agotado, opciones), retirar productos, crear, cambiar o borrar menús (del día, infantil, de grupo, de Navidad…), cambiar su diseño y prepararlos para imprimir en varios idiomas.

        Reglas:
        - Antes de cambiar nada, lee lo que hay (get_catalog o get_set_menus) para usar los ids correctos. Nunca inventes ids.
        - Si el encargo está claro, hazlo directamente, sin pedir permiso. Si falta algo importante (en qué familia, qué días, el precio del menú), pregunta con ask_user dando opciones concretas.
        - Borrar o retirar algo: SIEMPRE pregunta antes con ask_user (opciones tipo «Sí, esborra-ho» / «No»), y solo entonces llama a la herramienta con confirmed=true.
        - Nombres siempre en catalán y en castellano, bien escritos y con mayúscula inicial (como en una carta). Traduce tú.
        - Precios en euros con IVA. Si el usuario no da precio de un producto, déjalo sin precio (null): se pone durante el servicio. Las posiciones empiezan en 1.
        - Menús: secciones típicas Primers/Primeros (course 1), Segons/Segundos (course 2), Postres (course 3); choices = cuántos platos elige el cliente (normalmente 1). Si un plato existe en la carta usa su product_id; si no, ponle nombre. Para cambiar un menú, mándalo entero con su id.
        - Diseños de impresión: classic (normal), halloween, christmas (Navidad), easter (Pascua) y kids (infantil). Un menú infantil va con kids; si piden «posa-li el disseny de Nadal», usa set_menu_template.
        - Al guardar un menú se traduce solo al francés, inglés, alemán, ruso, italiano y neerlandés. Si piden imprimirlo, verlo impreso o en otros idiomas, usa print_set_menu con los idiomas pedidos: en el chat sale la hoja A4 con el botón para generar el PDF (una hoja por idioma). Tú no puedes imprimir directamente: dile que toque «Genera el PDF».
        - Las tarjetas (familia o menú) salen solas en el chat: no repitas listas largas en el texto. Responde corto: una o dos frases.
        - Al terminar un encargo, usa ask_user para ofrecer 2-4 siguientes pasos útiles (por ejemplo «Està bé així», «Imprimeix-lo», «Canvia el preu»). Las opciones son cortas (máximo 6 palabras), escritas como las diría el usuario, en su idioma.
        - Para dudas de uso de la app responde con pasos claros y breves (Markdown), sin usar herramientas.
        - Responde en el idioma del usuario (por defecto {$language}). Sin tecnicismos ni ids en el texto.

        Hoy es {$today}.

        ===== MANUAL DE LA APLICACIÓN =====
        {$this->assistant->manual()}
        ===== FIN DEL MANUAL =====
        PROMPT;
    }
}
