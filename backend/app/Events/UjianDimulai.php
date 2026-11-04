<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UjianDimulai implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $pesertaId,
        public string $waktuSelesai,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('peserta.'.$this->pesertaId)];
    }

    public function broadcastAs(): string
    {
        return 'UjianDimulai';
    }

    public function broadcastWith(): array
    {
        return [
            'peserta_id' => $this->pesertaId,
            'waktu_selesai' => $this->waktuSelesai,
        ];
    }
}
