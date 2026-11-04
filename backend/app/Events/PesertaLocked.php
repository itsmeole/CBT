<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PesertaLocked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $pesertaId,
        public int $sesiRuangId,
        public int $jumlahKunci,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sesi.'.$this->sesiRuangId)];
    }

    public function broadcastAs(): string
    {
        return 'PesertaLocked';
    }

    public function broadcastWith(): array
    {
        return [
            'peserta_id' => $this->pesertaId,
            'status' => 'terkunci',
            'jumlah_kunci' => $this->jumlahKunci,
        ];
    }
}
