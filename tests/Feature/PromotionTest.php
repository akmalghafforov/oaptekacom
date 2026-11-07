<?php

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Promotion;
use App\Models\PromotionAttachment;
use App\Models\PromotionItem;
use App\Models\PromotionQuoteRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function buyer(): User
    {
        $user = User::factory()->pharmacy()->create();
        Subscription::factory()->for($user)->create();

        return $user;
    }

    private function data(Organization $organization): array
    {
        return ['organization_id' => $organization->id, 'title' => 'Осенняя акция', 'description' => 'Описание', 'conditions' => 'Условия', 'city' => 'Душанбе',
            'starts_on' => now('Asia/Dushanbe')->toDateString(), 'ends_on' => now('Asia/Dushanbe')->addWeek()->toDateString()];
    }

    public function test_guests_and_unsubscribed_buyers_cannot_view_promotions_or_materials(): void
    {
        $promotion = Promotion::factory()->published()->create();
        $attachment = PromotionAttachment::factory()->for($promotion)->create();
        $this->get(route('promotions.index'))->assertRedirect(route('login'));
        $this->get(route('promotions.attachments.show', $attachment))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->pharmacy()->create())->get(route('promotions.show', $promotion))->assertRedirect(route('subscription.create'));
        $this->get(route('promotions.attachments.show', $attachment))->assertRedirect(route('subscription.create'));
    }

    public function test_supplier_can_only_create_and_edit_promotions_for_their_confirmed_company(): void
    {
        $user = User::factory()->wholesaler()->create();
        $other = Organization::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($other)->create();
        $this->actingAs($user)->post(route('promotions.store'), $this->data($other))->assertForbidden();
        $this->patch(route('promotions.update', $promotion), $this->data($other))->assertForbidden();
        $this->post(route('promotions.store'), $this->data($user->organization) + ['status' => 'published', 'author_id' => 999])->assertRedirect();

        $created = Promotion::where('organization_id', $user->organization_id)->sole();
        $this->assertSame(PromotionStatus::Draft, $created->status);
        $this->assertSame($user->id, $created->author_id);
        $this->assertDatabaseHas('audit_events', ['subject_type' => Promotion::class, 'subject_id' => $created->id, 'event' => 'promotion.created']);
    }

    public function test_buyer_and_unconfirmed_supplier_cannot_manage_promotions(): void
    {
        $promotion = Promotion::factory()->create();
        $this->actingAs($this->buyer())->get(route('promotions.create'))->assertForbidden();
        $user = User::factory()->wholesaler(Organization::factory()->wholesaler()->pending()->create())->create();
        $this->actingAs($user)->get(route('promotions.manage'))->assertRedirect(route('subscription.create'));
        $this->assertFalse($user->can('manage', $promotion));
    }

    public function test_moderation_requires_comments_and_supplier_edits_hide_publication(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $this->actingAs($supplier)->post(route('promotions.transition', $promotion), ['action' => 'submit'])->assertRedirect();
        $this->assertSame(PromotionStatus::PendingReview, $promotion->fresh()->status);
        $this->patch(route('promotions.update', $promotion), $this->data($supplier->organization))->assertForbidden();
        $this->post(route('promotions.transition', $promotion), ['action' => 'publish'])->assertForbidden();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('promotions.transition', $promotion), ['action' => 'revise'])->assertSessionHasErrors('comment');
        $this->assertSame(PromotionStatus::PendingReview, $promotion->fresh()->status);
        $this->post(route('promotions.transition', $promotion), ['action' => 'revise', 'comment' => 'Уточните условия'])->assertRedirect();
        $this->assertSame('Уточните условия', $promotion->fresh()->moderation_comment);
        $this->actingAs($supplier)->patch(route('promotions.update', $promotion), $this->data($supplier->organization))->assertRedirect();
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
        $this->post(route('promotions.transition', $promotion), ['action' => 'submit'])->assertRedirect();
        $this->actingAs($admin)->post(route('promotions.transition', $promotion), ['action' => 'publish'])->assertRedirect();
        $this->assertNotNull($promotion->fresh()->first_published_at);
        $this->actingAs($this->buyer())->get(route('promotions.show', $promotion))->assertSee('Осенняя акция');
        $this->actingAs($supplier)->patch(route('promotions.update', $promotion), $this->data($supplier->organization))->assertRedirect();
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
        $this->actingAs($this->buyer())->get(route('promotions.show', $promotion))->assertForbidden();
        $this->assertSame(1, AuditEvent::where('event', 'promotion.publish')->where('subject_id', $promotion->id)->count());
    }

    public function test_author_can_withdraw_review_and_admin_can_publish_directly(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create(['status' => PromotionStatus::PendingReview]);
        $this->actingAs($supplier)->post(route('promotions.transition', $promotion), ['action' => 'withdraw'])->assertRedirect();
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
        $this->actingAs(User::factory()->admin()->create())->post(route('promotions.transition', $promotion), ['action' => 'publish'])->assertRedirect();
        $this->assertSame(PromotionStatus::Published, $promotion->fresh()->status);
    }

    public function test_admin_cannot_change_company_after_first_publication(): void
    {
        $promotion = Promotion::factory()->published()->create();
        $other = Organization::factory()->wholesaler()->create();
        $this->actingAs(User::factory()->admin()->create())->patch(route('promotions.update', $promotion), $this->data($other))->assertSessionHasErrors('organization_id');

        $this->assertNotSame($other->id, $promotion->fresh()->organization_id);
    }

    #[TestWith(['2026-11-03 18:59:59 UTC', false])]
    #[TestWith(['2026-11-03 19:00:00 UTC', true])]
    #[TestWith(['2026-11-04 18:59:59 UTC', true])]
    #[TestWith(['2026-11-04 19:00:00 UTC', false])]
    public function test_active_dates_are_inclusive_in_dushanbe(string $time, bool $active): void
    {
        $this->travelTo(Carbon::parse($time));
        $promotion = Promotion::factory()->published()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        $user = $this->buyer();

        $response = $this->actingAs($user)->get(route('promotions.show', $promotion))->assertOk();
        $this->assertSame($active, $promotion->isActive());
        $this->assertSame($active, Promotion::active()->whereKey($promotion->id)->exists());
        if (! $active) {
            $response->assertDontSee('id="promotion-order"', false);
        }
    }

    public function test_filters_only_show_current_published_promotions_and_escape_content(): void
    {
        $promotion = Promotion::factory()->published()->create(['title' => '<script>Акция</script>', 'city' => 'Душанбе']);
        Promotion::factory()->published()->create(['title' => 'Другая акция', 'city' => 'Худжанд']);
        Promotion::factory()->create(['title' => 'Закрытый черновик']);
        Promotion::factory()->published()->create(['title' => 'Прошлая акция', 'ends_on' => now()->subDay()->toDateString()]);

        $this->actingAs($this->buyer())->get(route('promotions.index', ['company' => $promotion->organization_id, 'city' => 'Душанбе', 'q' => 'Акция']))
            ->assertSee('&lt;script&gt;Акция&lt;/script&gt;', false)->assertDontSee('<script>Акция</script>', false)->assertDontSee('Другая акция')->assertDontSee('Закрытый черновик')->assertDontSee('Прошлая акция');
    }

    public function test_private_files_require_visibility_and_support_download_and_video_ranges(): void
    {
        Storage::fake('local');
        $promotion = Promotion::factory()->published()->create();
        $file = PromotionAttachment::factory()->for($promotion)->create();
        Storage::disk('local')->put($file->path, '%PDF-1.7 test');
        $this->actingAs($this->buyer())->get(route('promotions.attachments.show', $file))->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('promotions.attachments.show', [$file, 'download' => 1]))->assertDownload('brochure.pdf');
        $promotion->update(['status' => PromotionStatus::Draft]);
        $this->get(route('promotions.attachments.show', $file))->assertForbidden();
        $other = User::factory()->wholesaler()->create();
        $this->actingAs($other)->get(route('promotions.attachments.show', $file))->assertForbidden();
    }

    #[TestWith(['bad.svg', '<svg></svg>', 'image/svg+xml'])]
    #[TestWith(['bad.pdf', '<script>alert(1)</script>', 'application/pdf'])]
    public function test_upload_rejects_disallowed_and_disguised_content(string $name, string $content, string $mime): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();

        $source = UploadedFile::fake()->createWithContent($name, $content);
        $this->actingAs($supplier)->post(route('promotions.attachments.store', $promotion), ['role' => 'material', 'file' => new UploadedFile($source->getPathname(), $name, $mime, null, true)])
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('promotion_attachments', 0);
        Storage::disk('local')->assertDirectoryEmpty('promotions');
    }

    public function test_banner_upload_is_private_and_hides_supplier_publication(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->published()->for($supplier->organization)->create();
        $this->actingAs($supplier)->post(route('promotions.attachments.store', $promotion), ['role' => 'banner', 'file' => UploadedFile::fake()->image('banner.png')])->assertRedirect();

        $attachment = $promotion->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
        $this->get(route('promotions.attachments.show', $attachment))->assertOk();
    }

    public function test_items_copy_company_price_list_characteristics_but_keep_independent_price(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $import = PriceListImport::factory()->for($supplier->organization, 'supplier')->create();
        $supplier->organization->update(['active_price_list_import_id' => $import->id]);
        $offer = Offer::factory()->for($supplier->organization)->create(['price_list_import_id' => $import->id, 'source_name' => 'Из прайса', 'price' => '200.00', 'quantity' => 99]);
        $payload = ['source_offer_id' => $offer->id, 'price' => '90.00', 'price_mode' => 'final', 'in_stock' => true];
        $this->actingAs($supplier)->post(route('promotions.items.store', $promotion), $payload)->assertRedirect();
        $item = $promotion->items()->sole();
        $offer->update(['price' => '300.00', 'quantity' => 0]);
        $this->assertSame('90.00', $item->fresh()->price);
        $this->assertSame('Из прайса', $item->name);
        $this->assertTrue($item->in_stock);
        $this->assertSame($offer->id, $item->source_offer_id);
        $other = Offer::factory()->create();
        $this->post(route('promotions.items.store', $promotion), array_replace($payload, ['source_offer_id' => $other->id]))->assertNotFound();
        $this->assertSame(1, $promotion->items()->count());
    }

    public function test_quote_requests_snapshot_contacts_and_are_visible_only_to_participants_and_admin(): void
    {
        $buyer = $this->buyer();
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->published()->for($supplier->organization)->create();
        $item = PromotionItem::factory()->quoteOnly()->for($promotion)->create();
        $this->actingAs($buyer)->post(route('promotion-quotes.store', $promotion), ['promotion_item_id' => $item->id, 'message' => 'Уточните цену'])->assertRedirect();
        $quote = PromotionQuoteRequest::query()->sole();
        $name = $buyer->name;
        $buyer->update(['name' => 'Новое имя']);
        $this->get(route('promotion-quotes.show', $quote))->assertSee($name)->assertSee('Уточните цену');
        $this->actingAs($supplier)->get(route('promotion-quotes.index'))->assertSee($name);
        $this->get(route('promotion-quotes.show', $quote))->assertSee('Уточните цену');
        $this->actingAs(User::factory()->admin()->create())->get(route('promotion-quotes.show', $quote))->assertSee($name);
        $this->actingAs($this->buyer())->get(route('promotion-quotes.show', $quote))->assertForbidden();
        $this->get(route('promotion-quotes.index'))->assertDontSee($name);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_quote_rejects_items_from_another_promotion(): void
    {
        $promotion = Promotion::factory()->published()->create();
        $item = PromotionItem::factory()->create();
        $this->actingAs($this->buyer())->post(route('promotion-quotes.store', $promotion), ['promotion_item_id' => $item->id, 'message' => 'Цена?'])->assertNotFound();
        $this->assertDatabaseCount('promotion_quote_requests', 0);
    }

    public function test_management_and_preview_pages_render_and_supplier_mode_cannot_buy(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        PromotionItem::factory()->for($promotion)->create();
        $this->actingAs($supplier)->get(route('promotions.create'))->assertSee('Новая акция');
        $this->get(route('promotions.manage'))->assertSee($promotion->title);
        $this->get(route('promotions.edit', $promotion))->assertSee('Добавить вручную');
        $this->get(route('promotions.show', $promotion))->assertSee($promotion->title);
        $this->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $promotion->items()->sole()->id, 'quantity' => 1]]])->assertForbidden();
    }

    public function test_active_mode_and_stale_mode_versions_guard_management(): void
    {
        config(['account-modes.enabled' => true]);
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $this->actingAs($user)->get(route('promotions.manage'))->assertOk();
        $version = session('account_mode.version');
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => $version])->assertRedirect();
        $this->get(route('promotions.manage'))->assertForbidden();
        $this->post(route('promotions.store'), $this->data($user->roleAssignments()->where('role', 'wholesaler')->first()->organization) + ['_mode_version' => $version])->assertStatus(409);
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_pdf_xlsx_and_mp4_uploads_use_verified_types_and_private_downloads(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $pdf = UploadedFile::fake()->createWithContent('brochure.pdf', "%PDF-1.7\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
        $video = UploadedFile::fake()->createWithContent('video.mp4', pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2'.str_repeat('0', 100));
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Скидка');
        $xlsx = UploadedFile::fake()->create('brochure.xlsx');
        (new Xlsx($spreadsheet))->save($xlsx->getPathname());
        $spreadsheet->disconnectWorksheets();
        $this->actingAs($supplier);
        foreach ([$pdf, $video, $xlsx] as $source) {
            $file = new UploadedFile($source->getPathname(), $source->getClientOriginalName(), null, null, true);
            $this->post(route('promotions.attachments.store', $promotion), ['role' => 'material', 'file' => $file])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(3, $promotion->attachments()->count());
        $this->assertSame(['application/pdf', 'video/mp4', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $promotion->attachments()->orderBy('id')->pluck('mime_type')->all());
        $attachment = $promotion->attachments()->where('mime_type', 'video/mp4')->sole();
        $this->withHeader('Range', 'bytes=0-3')->get(route('promotions.attachments.show', $attachment))->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/124')->assertHeader('Cache-Control', 'no-store, private');
        $this->flushHeaders();
        $attachment = $promotion->attachments()->where('mime_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->sole();
        $this->get(route('promotions.attachments.show', $attachment))->assertDownload('brochure.xlsx');
    }

    public function test_upload_size_and_banner_type_limits_are_enforced(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $this->actingAs($supplier);
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'banner', 'file' => UploadedFile::fake()->create('brochure.pdf', 1, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'material', 'file' => UploadedFile::fake()->image('big.png')->size(5121)])->assertSessionHasErrors('file');
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'material', 'file' => UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'material', 'file' => UploadedFile::fake()->create('big.mp4', 102401, 'video/mp4')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('promotion_attachments', 0);
    }

    public function test_rejected_promotions_require_an_explanation_and_edit_back_to_draft(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create(['status' => PromotionStatus::PendingReview]);
        $this->actingAs(User::factory()->admin()->create())->post(route('promotions.transition', $promotion), ['action' => 'reject', 'comment' => '   '])->assertSessionHasErrors('comment');
        $this->post(route('promotions.transition', $promotion), ['action' => 'reject', 'comment' => 'Неверные сведения'])->assertRedirect();
        $this->assertSame(PromotionStatus::Rejected, $promotion->fresh()->status);
        $this->assertNotNull($promotion->fresh()->reviewed_at);
        $this->actingAs($supplier)->patch(route('promotions.update', $promotion), $this->data($supplier->organization))->assertRedirect();
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
    }

    public function test_organization_logo_remains_private_and_pending_review_blocks_all_content_edits(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $item = PromotionItem::factory()->for($promotion)->create();
        $this->actingAs($supplier)->post(route('promotions.attachments.store', $promotion), ['role' => 'logo', 'file' => UploadedFile::fake()->image('logo.png')])->assertRedirect();
        $organization = $supplier->organization->fresh();
        Storage::disk('local')->assertExists($organization->logo_path);
        $this->get(route('promotions.logo', $organization))->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('promotions.transition', $promotion), ['action' => 'submit'])->assertRedirect();
        $this->delete(route('promotions.items.destroy', [$promotion, $item]))->assertForbidden();
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'banner', 'file' => UploadedFile::fake()->image('banner.png')])->assertForbidden();
        $this->actingAs($this->buyer())->get(route('promotions.logo', $organization))->assertForbidden();
        $this->assertModelExists($item);
    }

    public function test_creation_form_offers_registered_cities_and_optional_poster_upload(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('promotions.create'))
            ->assertViewHas('cities', config('registration.cities'))
            ->assertSee('<select id="city" name="city"', false)
            ->assertSee('Все города')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="poster" type="file"', false)
            ->assertSee('Постер акции')
            ->assertSee('data-date-picker', false)
            ->assertSee('data-date-min-field="starts_on"', false);
    }

    #[TestWith(['Душанбе'])]
    #[TestWith(['Худжанд'])]
    #[TestWith(['Истаравшан'])]
    #[TestWith([null])]
    public function test_creation_saves_selected_city_or_all_cities(?string $city): void
    {
        $supplier = User::factory()->wholesaler()->create();

        $this->actingAs($supplier)->post(route('promotions.store'), array_replace($this->data($supplier->organization), ['city' => $city]))->assertRedirect();

        $this->assertSame($city, Promotion::query()->sole()->city);
    }

    public function test_unknown_city_is_rejected_but_existing_city_can_be_preserved_on_edit(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $data = array_replace($this->data($supplier->organization), ['city' => 'Куляб']);
        $this->actingAs($supplier)->post(route('promotions.store'), $data)->assertSessionHasErrors('city');
        $this->assertDatabaseCount('promotions', 0);
        $promotion = Promotion::factory()->for($supplier->organization)->create(['city' => 'Куляб']);

        $this->get(route('promotions.edit', $promotion))->assertViewHas('cities', ['Душанбе', 'Худжанд', 'Истаравшан', 'Куляб']);
        $this->patch(route('promotions.update', $promotion), $data)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Куляб', $promotion->fresh()->city);
    }

    public function test_creating_promotion_with_poster_stores_private_banner_and_audit(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $poster = UploadedFile::fake()->image('poster.webp');

        $this->actingAs($supplier)->post(route('promotions.store'), $this->data($supplier->organization) + ['poster' => $poster])->assertRedirect();

        $promotion = Promotion::query()->sole();
        $attachment = $promotion->attachments()->sole();
        $this->assertSame(PromotionStatus::Draft, $promotion->status);
        $this->assertSame('banner', $attachment->role);
        $this->assertSame('poster.webp', $attachment->original_name);
        $this->assertSame('image/webp', $attachment->mime_type);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('audit_events', ['subject_type' => PromotionAttachment::class, 'subject_id' => $attachment->id, 'event' => 'promotion.attachment_created']);
        $this->get(route('promotions.edit', $promotion))->assertSee('src="'.route('promotions.attachments.show', $attachment).'"', false);
        $this->get(route('promotions.attachments.show', $attachment))->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->buyer())->get(route('promotions.attachments.show', $attachment))->assertForbidden();
    }

    public function test_replacing_poster_preserves_attachment_identity_and_hides_supplier_publication(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->published()->for($supplier->organization)->create();
        $attachment = PromotionAttachment::factory()->for($promotion)->create(['role' => 'banner', 'path' => 'promotions/old.png', 'original_name' => 'old.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put($attachment->path, 'old poster');

        $this->actingAs($supplier)->post(route('promotions.update', $promotion), $this->data($supplier->organization) + ['_method' => 'PATCH', 'poster' => UploadedFile::fake()->image('new.png')])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $promotion->attachments()->count());
        $this->assertSame($attachment->id, $promotion->attachments()->sole()->id);
        $this->assertSame('new.png', $attachment->fresh()->original_name);
        $this->assertNotSame('promotions/old.png', $attachment->fresh()->path);
        Storage::disk('local')->assertExists($attachment->fresh()->path);
        $this->assertSame(PromotionStatus::Draft, $promotion->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['subject_type' => PromotionAttachment::class, 'subject_id' => $attachment->id, 'event' => 'promotion.attachment_updated']);
        $this->patch(route('promotions.update', $promotion), $this->data($supplier->organization))->assertRedirect();
        $this->assertSame('new.png', $attachment->fresh()->original_name);
    }

    #[TestWith(['pdf'])]
    #[TestWith(['svg'])]
    #[TestWith(['oversize'])]
    public function test_invalid_poster_creates_no_promotion_or_uploaded_file(string $case): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $poster = match ($case) {
            'pdf' => UploadedFile::fake()->create('poster.pdf', 10, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('poster.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'oversize' => UploadedFile::fake()->image('poster.jpg')->size(5121),
        };

        $this->actingAs($supplier)->post(route('promotions.store'), $this->data($supplier->organization) + ['poster' => $poster])->assertSessionHasErrors('poster');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_company_authorization_removes_uploaded_poster_without_creating_records(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $otherCompany = Organization::factory()->wholesaler()->create();

        $this->actingAs($supplier)->post(route('promotions.store'), $this->data($otherCompany) + ['poster' => UploadedFile::fake()->image('poster.jpg')])->assertForbidden();

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('promotion_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
