<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PesertaProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $pesertaId,
        public int $sesiRuangId,
        public int $terjawab,
        public int $sisaDetik,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sesi.'.$this->sesiRuangId)];
    }

    public function broadcastAs(): string
    {
        return 'PesertaProgress';
    }

    public function broadcastWith(): array
    {
        return [
            'peserta_id' => $this->pesertaId,
            'terjawab' => $this->terjawab,
            'sisa_detik' => $this->sisaDetik,
        ];
    }
}
