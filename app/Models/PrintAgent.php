<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A copy of the Windows print program on a PC of the bar. It authenticates with a secret
 * key (only its hash is stored) and listens on its own channel for "there are jobs".
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property string $channel
 * @property string|null $machine
 * @property string|null $version
 * @property list<string>|null $system_printers Windows printers installed on that PC
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrintAgent extends Model
{
    /** Seen within this many seconds = connected (it checks in every 20 s). */
    public const ONLINE_SECONDS = 60;

    /** Jobs older than this are not printed when the program comes back (stale orders). */
    public const MAX_JOB_AGE_MINUTES = 30;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'system_printers' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Printer, $this>
     */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    /**
     * Create a program with a new key; the key is returned once and never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name): array
    {
        $agent = new self(['name' => $name, 'channel' => Str::lower(Str::random(32))]);
        $token = $agent->newToken();
        $agent->save();

        return [$agent, $token];
    }

    public function newToken(): string
    {
        $token = Str::random(48);
        $this->token_hash = hash('sha256', $token);

        return $token;
    }

    public static function findByToken(?string $token): ?self
    {
        return $token === null || $token === '' ? null : self::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * What the person pastes in the program: the server address and the key in one line.
     */
    public static function connectionKey(string $token): string
    {
        return rtrim(url('/'), '/').'/api/print-agent/'.$token;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
