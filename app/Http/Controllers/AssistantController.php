<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\AssistantException;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function page(): Response
    {
        return Inertia::render('admin/Assistant');
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = AssistantConversation::query()
            ->where('user_id', $this->user($request)->id)
            ->latest('updated_at')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (AssistantConversation $conversation) => $this->conversationData($conversation));

        return response()->json([
            'conversations' => $conversations,
            'configured' => $this->assistant->configured(),
            'model' => $this->assistant->model(),
        ]);
    }

    public function show(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);

        return response()->json([
            'conversation' => $this->conversationData($conversation),
            'messages' => $conversation->messages()->orderBy('id')->get()->map(fn (AssistantMessage $message) => $this->messageData($message)),
        ]);
    }

    public function update(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $conversation->update(['title' => trim($data['title'])]);

        return response()->json(['conversation' => $this->conversationData($conversation)]);
    }

    public function destroy(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    public function manual(): JsonResponse
    {
        return response()->json(['manual' => $this->assistant->manual()]);
    }

    /**
     * Ask a question. The answer is streamed as newline-delimited JSON events:
     * start (conversation), delta (text), done (saved messages) or error.
     */
    public function chat(Request $request): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.AssistantService::MESSAGE_MAX],
            'conversationId' => ['nullable', 'integer'],
            'context' => ['nullable', 'in:tpv,admin'],
            'locale' => ['nullable', 'in:ca,es'],
        ]);

        if (! $this->assistant->configured()) {
            return response()->json(['message' => __('assistant.not_configured')], 503);
        }

        $user = $this->user($request);
        $text = trim($data['message']);
        $created = empty($data['conversationId']);

        if ($created) {
            $conversation = AssistantConversation::query()->create(['user_id' => $user->id, 'title' => Str::limit(Str::squish($text), 80)]);
        } else {
            $conversation = AssistantConversation::query()->findOrFail((int) $data['conversationId']);
            $this->authorizeOwner($request, $conversation);
        }

        $question = $conversation->messages()->create(['role' => AssistantMessage::USER, 'content' => $text]);

        $history = $conversation->messages()
            ->latest('id')
            ->limit(AssistantService::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();

        $system = $this->assistant->systemPrompt($user, $data['locale'] ?? app()->getLocale(), $data['context'] ?? 'admin');

        return response()->stream(function () use ($conversation, $question, $history, $system, $created): void {
            $send = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE)."\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            $send(['type' => 'start', 'conversation' => $this->conversationData($conversation), 'question' => $this->messageData($question)]);

            $answer = '';
            $usage = ['prompt_tokens' => null, 'completion_tokens' => null];
            $error = null;

            try {
                $stream = $this->assistant->stream($system, $history);
                $finished = true;

                foreach ($stream as $delta) {
                    $answer .= $delta;
                    $send(['type' => 'delta', 'text' => $delta]);

                    if (connection_aborted()) {
                        $finished = false;
                        break;
                    }
                }

                if ($finished) {
                    $usage = $stream->getReturn();
                }
            } catch (AssistantException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $error = __('assistant.unavailable');
            }

            if (trim($answer) === '') {
                $question->delete();

                if ($created) {
                    $conversation->delete();
                }

                $send(['type' => 'error', 'message' => $error ?? __('assistant.unavailable'), 'discarded' => $created ? 'conversation' : 'question']);

                return;
            }

            $reply = $conversation->messages()->create([
                'role' => AssistantMessage::ASSISTANT,
                'content' => $answer,
                'model' => $this->assistant->model(),
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
            ]);
            $conversation->touch();

            $send(['type' => 'done', 'message' => $this->messageData($reply), 'conversation' => $this->conversationData($conversation), 'error' => $error]);
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function authorizeOwner(Request $request, AssistantConversation $conversation): void
    {
        abort_unless($conversation->user_id === $this->user($request)->id, 404);
    }

    /**
     * @return array{id: int, title: string|null, updatedAt: string}
     */
    private function conversationData(AssistantConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'updatedAt' => $conversation->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, role: string, content: string, createdAt: string}
     */
    private function messageData(AssistantMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }
}
