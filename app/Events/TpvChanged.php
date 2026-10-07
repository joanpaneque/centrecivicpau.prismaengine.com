<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells connected devices that something changed so they pull. The payload is only a hint;
 * the data always travels through the pull endpoint.
 */
class TpvChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(public array $scopes, public ?string $deviceUuid = null, public ?string $notice = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tpv');
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function notify(array $scopes, ?string $deviceUuid = null, ?string $notice = null): void
    {
        if ($scopes === []) {
            return;
        }

        try {
            broadcast(new self(array_values(array_unique($scopes)), $deviceUuid, $notice));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
