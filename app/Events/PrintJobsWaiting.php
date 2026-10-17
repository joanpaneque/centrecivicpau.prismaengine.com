<?php

namespace App\Events;

use App\Models\PrintAgent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Wakes a Windows print program up the moment it has something to print. Only a hint, on
 * the program's own unguessable channel: the jobs themselves travel over its authenticated
 * pull, which it also does every few seconds in case the websocket is down.
 */
class PrintJobsWaiting implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $channel) {}

    public function broadcastOn(): Channel
    {
        return new Channel('print-agent.'.$this->channel);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['at' => now()->toIso8601String()];
    }

    public function broadcastAs(): string
    {
        return 'jobs';
    }

    public static function notify(PrintAgent $agent): void
    {
        try {
            broadcast(new self($agent->channel));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
