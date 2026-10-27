<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class AssetVersioningTest extends TestCase
{
    public function test_html_uses_current_manifest_assets_and_requires_revalidation(): void
    {
        $buildDirectory = 'build-test-'.bin2hex(random_bytes(8));
        $manifestDirectory = public_path($buildDirectory);
        File::ensureDirectoryExists($manifestDirectory);
        Vite::useHotFile($manifestDirectory.'/hot')->useBuildDirectory($buildDirectory);

        try {
            File::put($manifestDirectory.'/manifest.json', json_encode([
                'resources/css/app.css' => ['src' => 'resources/css/app.css', 'file' => 'assets/app-test1234.css', 'isEntry' => true],
                'resources/js/app.js' => ['src' => 'resources/js/app.js', 'file' => 'assets/app-test1234.js', 'isEntry' => true],
            ], JSON_THROW_ON_ERROR));

            $this->get(route('login'))
                ->assertSeeHtml('href="'.asset($buildDirectory.'/assets/app-test1234.css').'"')
                ->assertSeeHtml('src="'.asset($buildDirectory.'/assets/app-test1234.js').'"')
                ->assertHeader('Cache-Control', 'no-cache, private');
        } finally {
            File::deleteDirectory($manifestDirectory);
        }
    }
}
