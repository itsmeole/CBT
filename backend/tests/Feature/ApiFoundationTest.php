<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_time_returns_epoch_ms(): void
    {
        $this->getJson('/api/v1/time')->assertOk()->assertJsonStructure(['time']);
    }

    public function test_unknown_api_route_uses_json(): void
    {
        $this->getJson('/api/v1/tidak-ada')->assertNotFound();
    }
}

