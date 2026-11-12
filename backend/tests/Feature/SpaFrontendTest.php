<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class SpaFrontendTest extends TestCase
{
    private string $berkas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->berkas = public_path('app.html');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->berkas)) {
            unlink($this->berkas);
        }
        parent::tearDown();
    }

    public function test_any_non_api_page_returns_the_built_frontend_when_it_exists(): void
    {
        file_put_contents($this->berkas, '<html><body>CBT SPA</body></html>');

        foreach (['/', '/staf/jadwal'] as $alamat) {
            $respons = $this->get($alamat)->assertOk();
            $this->assertInstanceOf(BinaryFileResponse::class, $respons->baseResponse);
            $this->assertSame(realpath($this->berkas), $respons->baseResponse->getFile()->getRealPath());
        }
    }

    public function test_api_and_unknown_api_paths_are_not_swallowed_by_the_frontend(): void
    {
        file_put_contents($this->berkas, '<html><body>CBT SPA</body></html>');

        $this->getJson('/api/v1/rute-yang-tidak-ada')->assertNotFound();
        $this->assertNotInstanceOf(BinaryFileResponse::class, $this->get('/api/v1/time')->assertOk()->baseResponse);
    }

    public function test_nested_paths_are_not_found_when_the_frontend_has_not_been_built(): void
    {
        $this->get('/staf/jadwal')->assertNotFound();
    }
}
