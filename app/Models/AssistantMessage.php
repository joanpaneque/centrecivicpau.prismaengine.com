<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property string|null $model
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AssistantConversation $conversation
 */
class AssistantMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
