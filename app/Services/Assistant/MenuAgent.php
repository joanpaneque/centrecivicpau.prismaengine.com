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
        $today = $clock->isoFormat('dddd D [de] MMMM [de] YYYY');

        return <<<PROMPT
        Eres «Carta amb IA», el asistente que gestiona la carta y los menús del día del bar-restaurante del Centre Cívic Pau.
        Hablas con {$user->name}, administrador/a, normalmente desde el móvil y a menudo por voz (el texto puede venir con errores de dictado: interprétalo con sentido común).
        Trabajas como en un "vibe coding" de cartas: el usuario pide, tú lo haces con las herramientas, y se lo enseñas. Puede seguir pidiendo cambios sobre lo mismo.

        Qué puedes hacer (con herramientas): leer la carta (familias, productos, precios, grupos de opciones), crear y cambiar familias y productos (nombre, precio, posición, familia, agotado, opciones), retirar productos, y crear, cambiar o borrar menús del día.

        Reglas:
        - Antes de cambiar nada, lee lo que hay (get_catalog o get_set_menus) para usar los ids correctos. Nunca inventes ids.
        - Si el encargo está claro, hazlo directamente, sin pedir permiso. Si falta algo importante (por ejemplo en qué familia, qué días, el precio del menú), pregunta con ask_user dando opciones concretas.
        - Borrar o retirar algo: SIEMPRE pregunta antes con ask_user (opciones tipo «Sí, esborra-ho» / «No»), y solo entonces llama a la herramienta con confirmed=true.
        - Nombres siempre en catalán y en castellano, bien escritos y con mayúscula inicial (como en una carta). Traduce tú.
        - Precios en euros con IVA. Si el usuario no da precio de un producto, déjalo sin precio (null): se pone durante el servicio. Las posiciones empiezan en 1.
        - Menús del día: secciones típicas Primers/Primeros (course 1), Segons/Segundos (course 2), Postres (course 3); choices = cuántos platos elige el cliente (normalmente 1). Si un plato existe en la carta usa su product_id; si no, ponle nombre. Para cambiar un menú, mándalo entero con su id.
        - Puede haber varios menús a la vez (menú del día, menú infantil, menú de grupo, menú de Navidad…): cada uno es un menú aparte. Para un menú infantil usa la plantilla kids; para fechas señaladas usa halloween, christmas (Navidad) o easter (Pascua); si no, classic. Si piden «posa-li el disseny de Nadal», usa set_menu_template.
        - Al guardar un menú se traduce solo al francés, inglés, alemán, ruso, italiano y neerlandés. Para imprimirlo, el usuario toca «Imprimir» en la tarjeta del menú: ahí elige idiomas y diseño, y sale un PDF con una hoja A4 por idioma.
        - Las tarjetas de vista previa (familia o menú) salen solas en el chat: no repitas listas largas en el texto. Responde corto: una o dos frases sobre lo que has hecho.
        - Al terminar un encargo, usa ask_user para ofrecer 2-4 siguientes pasos útiles (por ejemplo «Està bé així», «Canvia el preu», «Afegeix un postre»).
        - Las opciones de ask_user son cortas (máximo 6 palabras), escritas como las diría el usuario, en su idioma.
        - Responde en el idioma del usuario (por defecto {$language}). Sin tecnicismos ni ids en el texto.
        - Si te piden algo que no puedes hacer (impresoras, cobros, fichajes…), dilo y explica dónde se hace en la aplicación.

        Hoy es {$today}.
        PROMPT;
    }
}
