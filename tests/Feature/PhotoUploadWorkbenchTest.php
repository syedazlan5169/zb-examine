<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class PhotoUploadWorkbenchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_local_workbench_route_is_available_for_mobile_photo_testing(): void
    {
        $response = $this->get('/dev/photo-upload-workbench');

        $response->assertOk();
        $response->assertSee('Papan Ujian Muat Naik Gambar');
        $response->assertSee('Pilih Gambar Sedia Ada');
        $response->assertDontSee('X-Photo-Upload-Token');
    }

    public function test_production_bootstrap_does_not_register_the_workbench_route(): void
    {
        $result = shell_exec('cd '.escapeshellarg(base_path()).' && APP_ENV=production php artisan route:list --path=/dev/photo-upload-workbench 2>/dev/null || true');

        $this->assertStringNotContainsString('/dev/photo-upload-workbench', (string) $result);
    }

    public function test_workbench_renders_csrf_token_and_the_real_examination_form_has_its_own_photo_mount_without_workbench_content(): void
    {
        $workbench = $this->get('/dev/photo-upload-workbench');
        $workbench->assertOk();
        $workbench->assertSee('csrf-token', false);

        $realForm = $this->get(route('examinations.create'));
        $realForm->assertOk();

        $content = $realForm->getContent();

        // Step 3B.4: the real form now genuinely mounts the shared photo widget.
        $this->assertStringContainsString('data-role="camera-input"', $content);
        $this->assertStringContainsString('data-role="library-input"', $content);
        $this->assertStringContainsString('id="examination-photos"', $content);

        // But never any workbench-only/dev-only content.
        $this->assertStringNotContainsString('photo-upload-workbench', $content);
        $this->assertStringNotContainsString('data-role="slow-mode-toggle"', $content);
        $this->assertStringNotContainsString('X-Photo-Upload-Token', $content);
    }

    public function test_workbench_renders_the_dev_only_slow_test_mode_toggle(): void
    {
        $response = $this->get('/dev/photo-upload-workbench');

        $response->assertOk();
        $response->assertSee('data-role="slow-mode-toggle"', false);
        $response->assertSee('Mod ujian perlahan (pembangunan sahaja)');
    }

    public function test_workbench_config_exposes_localized_retry_remove_and_state_labels(): void
    {
        $response = $this->get('/dev/photo-upload-workbench');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('retryRemove', $content);
        $this->assertStringContainsString('Cuba Padam Lagi', $content);
        $this->assertStringContainsString('Gagal memadam foto', $content);
        $this->assertStringContainsString('retry_cleanup_failed', $content);
        $this->assertStringContainsString('Perlu bersihkan sebelum cuba semula', $content);
        $this->assertStringContainsString('originalLabel', $content);
        $this->assertStringContainsString('optimizedLabel', $content);
    }
}
