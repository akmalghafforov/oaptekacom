<?php

namespace Tests\Feature;

use App\Models\CatalogBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogBannerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_renders_session_csrf_metadata_for_banner_requests(): void
    {
        CatalogBanner::factory()->create();

        $this->actingAs(User::factory()->wholesaler()->create())
            ->withSession(['_token' => 'catalog-banner-csrf-token'])
            ->get(route('catalog'))
            ->assertOk()
            ->assertSee('<meta name="csrf-token" content="catalog-banner-csrf-token">', false);
    }

    public function test_catalog_replaces_results_only_for_empty_search_with_eligible_banners(): void
    {
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('catalog'))->assertOk()->assertDontSee('data-banner-carousel')->assertSee('data-catalog-content');
        $banner = CatalogBanner::factory()->create();
        $this->get(route('catalog'))->assertOk()->assertSee('data-banner-carousel')->assertSee('data-catalog-content  hidden', false);
        $this->get(route('catalog', ['q' => 'асп']))->assertOk()->assertSee('data-banner-carousel')->assertDontSee('data-catalog-content  hidden', false);
        $this->get(route('catalog.search', ['q' => 'асп']))->assertOk()->assertSee('data-catalog-fallback');
        $banner->update(['is_active' => false]);
        $this->get(route('catalog'))->assertOk()->assertDontSee('data-banner-carousel');
    }

    public function test_rotation_is_persistent_fair_and_starts_at_different_positions_for_customers(): void
    {
        $banners = CatalogBanner::factory()->count(3)->create();
        $first = User::factory()->wholesaler()->create();
        $second = User::factory()->wholesaler()->create();
        $this->actingAs($first)->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('banner.id', $banners[0]->id);
        $this->actingAs($second)->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('banner.id', $banners[1]->id);
        $this->actingAs($first)->postJson(route('catalog.banners.next'), ['banner_id' => $banners[0]->id])->assertOk()->assertJsonPath('banner.id', $banners[1]->id);
        $this->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('banner.id', $banners[2]->id);
        $this->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('banner.id', $banners[1]->id);
    }

    public function test_eligibility_includes_start_boundary_excludes_end_boundary_and_reconciles_changes(): void
    {
        $this->freezeTime();
        $active = CatalogBanner::factory()->create(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        CatalogBanner::factory()->create(['is_active' => false]);
        CatalogBanner::factory()->create(['starts_at' => now()->addSecond()]);
        CatalogBanner::factory()->create(['ends_at' => now()]);
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('ids', [$active->id])->assertJsonPath('banner.duration', 8);
        $active->update(['is_active' => false]);
        $this->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('banner', null);
        $this->travel(2)->seconds();
        $this->postJson(route('catalog.banners.next'))->assertOk()->assertJsonCount(1, 'ids');
    }

    public function test_new_and_removed_banners_do_not_repeat_an_already_seen_banner_in_a_cycle(): void
    {
        $banners = CatalogBanner::factory()->count(3)->create();
        $this->actingAs(User::factory()->wholesaler()->create());
        $this->postJson(route('catalog.banners.next'))->assertJsonPath('banner.id', $banners[0]->id);
        $banners[1]->delete();
        $new = CatalogBanner::factory()->create();
        $this->postJson(route('catalog.banners.next'), ['previous' => true])->assertJsonPath('banner.id', $new->id);
        $this->postJson(route('catalog.banners.next'))->assertJsonPath('banner.id', $banners[2]->id);
    }

    public function test_impressions_require_owned_appearance_one_second_and_are_idempotent(): void
    {
        $this->freezeTime();
        $banner = CatalogBanner::factory()->create();
        $user = User::factory()->wholesaler()->create();
        $appearance = $this->actingAs($user)->postJson(route('catalog.banners.next'))->assertOk()->json('appearance');
        $url = route('catalog.banners.impression', $appearance);
        $this->postJson($url)->assertUnprocessable();
        $this->travel(1)->seconds();
        $this->actingAs(User::factory()->wholesaler()->create())->postJson($url)->assertNotFound();
        $this->actingAs($user)->postJson($url)->assertNoContent();
        $this->postJson($url)->assertNoContent();
        $this->assertSame(1, $banner->fresh()->impressions);
        $appearance = $this->postJson(route('catalog.banners.next'))->json('appearance');
        $this->travel(1)->seconds();
        $this->postJson(route('catalog.banners.impression', $appearance))->assertNoContent();
        $this->assertSame(2, $banner->fresh()->impressions);
    }

    public function test_admin_preview_and_ineligible_banner_do_not_count(): void
    {
        config(['auth.admin_two_factor_enabled' => false]);
        $this->freezeTime();
        $banner = CatalogBanner::factory()->create();
        $appearance = $this->actingAs(User::factory()->admin()->create())->postJson(route('catalog.banners.next'))->assertOk()->assertJsonPath('impression_url', null)->json('appearance');
        $this->travel(2)->seconds();
        $this->postJson(route('catalog.banners.impression', $appearance))->assertForbidden();
        $appearance = $this->actingAs(User::factory()->wholesaler()->create())->postJson(route('catalog.banners.next'))->json('appearance');
        $banner->update(['is_active' => false]);
        $this->travel(2)->seconds();
        $this->postJson(route('catalog.banners.impression', $appearance))->assertNotFound();
        $this->assertSame(0, $banner->fresh()->impressions);
    }

    public function test_destination_escapes_content_encodes_whatsapp_and_hides_ineligible_pages(): void
    {
        $banner = CatalogBanner::factory()->create(['title' => 'Спецпредложение', 'description' => '<script>alert(1)</script>', 'whatsapp_message' => 'Здравствуйте & цена?']);
        $this->actingAs(User::factory()->wholesaler()->create())->get(route('catalog.banners.show', $banner))
            ->assertOk()->assertSee('Спецпредложение')->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('https://wa.me/992900000000?text='.rawurlencode('Здравствуйте & цена?'), false);
        $banner->update(['is_active' => false]);
        $this->get(route('catalog.banners.show', $banner))->assertNotFound();
    }

    public function test_only_admins_can_manage_banners_and_guests_cannot_access_customer_endpoints(): void
    {
        $this->get(route('catalog'))->assertRedirect(route('login'));
        $this->postJson(route('catalog.banners.next'))->assertUnauthorized();
        $banner = CatalogBanner::factory()->create();
        $this->actingAs(User::factory()->wholesaler()->create());
        $this->get(route('admin.catalog-banners.index'))->assertForbidden();
        $this->postJson(route('admin.catalog-banners.store'), [])->assertForbidden();
        $this->putJson(route('admin.catalog-banners.update', $banner), [])->assertForbidden();
        $this->patchJson(route('admin.catalog-banners.activation', $banner), ['is_active' => false])->assertForbidden();
        $this->deleteJson(route('admin.catalog-banners.destroy', $banner))->assertForbidden();
    }

    public function test_admin_can_create_preview_edit_activate_and_delete_with_image_cleanup(): void
    {
        Storage::fake('local');
        config(['auth.admin_two_factor_enabled' => false]);
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.catalog-banners.create'))->assertOk()->assertSee('1440 × 480')->assertSee('1024 × 512')->assertSee('720 × 540');
        $this->post(route('admin.catalog-banners.store'), $this->payload())->assertRedirect(route('admin.catalog-banners.index'));
        $banner = CatalogBanner::query()->sole();
        $original = $banner->desktop_image;
        Storage::disk('local')->assertExists($original);
        $this->assertSame('992900000000', $banner->whatsapp_number);
        $this->get(route('admin.catalog-banners.edit', $banner))->assertOk()->assertSee('data-crop-existing');
        $this->get(route('catalog.banners.image', [$banner, 'desktop']))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->put(route('admin.catalog-banners.update', $banner), $this->payload(['name' => 'Новый баннер']))->assertRedirect();
        Storage::disk('local')->assertMissing($original);
        $this->assertSame('Новый баннер', $banner->fresh()->name);
        $this->patch(route('admin.catalog-banners.activation', $banner), ['is_active' => false])->assertRedirect();
        $this->assertFalse($banner->fresh()->is_active);
        $this->get(route('admin.catalog-banners.index'))->assertOk()->assertSee('Показы')->assertSee('Новый баннер');
        $paths = [$banner->fresh()->desktop_image, $banner->fresh()->tablet_image, $banner->fresh()->mobile_image];
        $this->delete(route('admin.catalog-banners.destroy', $banner))->assertRedirect();
        $this->assertDatabaseMissing('catalog_banners', ['id' => $banner->id]);
        Storage::disk('local')->assertMissing($paths);
    }

    public function test_invalid_uploads_schedules_duration_and_contact_are_rejected_atomically(): void
    {
        Storage::fake('local');
        config(['auth.admin_two_factor_enabled' => false]);
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('admin.catalog-banners.store'), $this->payload([
            'desktop_image' => UploadedFile::fake()->image('bad.jpg', 20, 20),
            'tablet_image' => UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'),
            'mobile_image' => UploadedFile::fake()->image('big.jpg', 720, 540)->size(6000),
            'duration_seconds' => 0, 'starts_at' => '2026-11-14 10:00', 'ends_at' => '2026-11-13 10:00', 'whatsapp_number' => 'invalid',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['desktop_image', 'tablet_image', 'mobile_image', 'duration_seconds', 'ends_at', 'whatsapp_number']);
        $this->assertDatabaseCount('catalog_banners', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_edit_retains_images_and_allows_end_date_without_start_date(): void
    {
        config(['auth.admin_two_factor_enabled' => false]);
        $banner = CatalogBanner::factory()->create();
        $payload = $this->payload(['starts_at' => null, 'ends_at' => now()->addDay()->format('Y-m-d H:i')]);
        unset($payload['desktop_image'], $payload['tablet_image'], $payload['mobile_image']);
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.catalog-banners.update', $banner), $payload)->assertRedirect();
        $this->assertSame('catalog-banners/desktop.jpg', $banner->fresh()->desktop_image);
        $this->assertNotNull($banner->fresh()->ends_at);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Весенняя акция', 'title' => 'Предложение', 'description' => 'Описание предложения',
            'whatsapp_number' => '+992 (90) 000-00-00', 'whatsapp_message' => 'Здравствуйте!',
            'duration_seconds' => 8, 'is_active' => 1,
            'desktop_image' => UploadedFile::fake()->image('desktop.jpg', 1440, 480),
            'tablet_image' => UploadedFile::fake()->image('tablet.jpg', 1024, 512),
            'mobile_image' => UploadedFile::fake()->image('mobile.jpg', 720, 540),
        ], $overrides);
    }
}
