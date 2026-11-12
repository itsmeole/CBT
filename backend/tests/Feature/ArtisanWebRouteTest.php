<?php

namespace Tests\Feature;

use Tests\TestCase;

// Rute sementara /_artisan/*. Hapus test ini bersama rutenya.
class ArtisanWebRouteTest extends TestCase
{
    private string $token = 'rahasia-panjang-1234567890';

    public function test_disabled_without_token_config(): void
    {
        config(['cbt.artisan_token' => null]);
        $this->get('/_artisan/view-clear?token=apa')->assertNotFound();
    }

    public function test_wrong_token_and_unknown_command_404(): void
    {
        config(['cbt.artisan_token' => $this->token]);
        $this->get('/_artisan/view-clear?token=salah')->assertNotFound();
        $this->get('/_artisan/migrate-fresh?token='.$this->token)->assertNotFound();
    }

    public function test_runs_whitelisted_command(): void
    {
        config(['cbt.artisan_token' => $this->token]);
        $this->get('/_artisan/view-clear?token='.$this->token)->assertOk()->assertSee('exit code: 0');
        $this->get('/_artisan/migrate?token='.$this->token)->assertOk()->assertSee('exit code: 0');
    }

    public function test_seed_rejects_unknown_class(): void
    {
        config(['cbt.artisan_token' => $this->token]);
        $this->get('/_artisan/seed?token='.$this->token.'&class=..\\Evil')->assertStatus(422);
    }
}
