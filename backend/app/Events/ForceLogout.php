<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ForceLogout implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $pesertaId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('peserta.'.$this->pesertaId)];
    }

    public function broadcastAs(): string
    {
        return 'ForceLogout';
    }

    public function broadcastWith(): array
    {
        return ['peserta_id' => $this->pesertaId];
    }
}
