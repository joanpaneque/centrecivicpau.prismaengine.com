<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind help (questions about the app) or carta (catalog and menus with tools)
 * @property string|null $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class AssistantConversation extends Model
{
    public const HELP = 'help';

    public const CARTA = 'carta';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }
}
