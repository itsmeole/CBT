<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PesertaSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $pesertaId, public int $sesiRuangId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sesi.'.$this->sesiRuangId)];
    }

    public function broadcastAs(): string
    {
        return 'PesertaSubmitted';
    }

    public function broadcastWith(): array
    {
        return ['peserta_id' => $this->pesertaId, 'status' => 'selesai'];
    }
}
