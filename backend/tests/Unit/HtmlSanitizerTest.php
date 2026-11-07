<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizerService;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_uploaded_audio_is_kept_with_controls(): void
    {
        $hasil = HtmlSanitizerService::sanitize('<p>Dengarkan</p><audio controls src="/storage/soal/audio_1.mp3" onerror="x()"></audio>');

        $this->assertStringContainsString('<audio', $hasil);
        $this->assertStringContainsString('src="/storage/soal/audio_1.mp3"', $hasil);
        $this->assertStringContainsString('controls', $hasil);
        $this->assertStringNotContainsString('onerror', $hasil);
    }

    public function test_audio_from_external_or_script_sources_loses_its_src(): void
    {
        $luar = HtmlSanitizerService::sanitize('<audio controls src="https://evil.example/a.mp3"></audio>');
        $skrip = HtmlSanitizerService::sanitize('<audio controls src="javascript:alert(1)"></audio>');

        $this->assertStringNotContainsString('evil.example', $luar);
        $this->assertStringNotContainsString('javascript', $skrip);
    }

    public function test_dangerous_tags_are_still_removed(): void
    {
        $hasil = HtmlSanitizerService::sanitize('<p>a</p><script>alert(1)</script><iframe src="x"></iframe>');

        $this->assertStringNotContainsString('script', $hasil);
        $this->assertStringNotContainsString('iframe', $hasil);
    }

    public function test_latex_delimiters_survive_sanitization(): void
    {
        $this->assertStringContainsString('$\frac{1}{2}$', HtmlSanitizerService::sanitize('<p>Hitung $\frac{1}{2}$</p>'));
    }
}
