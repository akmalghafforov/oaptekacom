<?php

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Promotion;
use App\Models\PromotionAttachment;
use App\Models\PromotionItem;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PromotionEditorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function document(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }

    /** @return array{name: string, price: string, price_mode: string, minimum_quantity: int, in_stock: string} */
    private function itemData(): array
    {
        return ['name' => 'Новый товар', 'price' => '12.50', 'price_mode' => 'final', 'minimum_quantity' => 1, 'in_stock' => '1'];
    }

    public function test_failed_product_edit_recovers_only_that_row_and_links_its_errors(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create(['description' => 'Сохранённое описание']);
        $item = PromotionItem::factory()->for($promotion)->create(['price' => '15.00']);
        $other = PromotionItem::factory()->for($promotion)->create(['price' => '25.00']);
        $url = route('promotions.edit', $promotion);

        $this->actingAs($supplier)->from($url)->patch(route('promotions.items.update', [$promotion, $item]), [
            ...$this->itemData(), '_promotion_form' => 'item-'.$item->id, 'price' => '-1', 'price_mode' => 'pharmacy_discount', 'in_stock' => '0', 'description' => 'Чужое описание',
        ])->assertRedirect($url);
        $document = $this->document($this->get($url)->assertSee('Сохранённое описание')->assertDontSee('Чужое описание'));

        $this->assertSame('-1', $document->evaluate('string(//input[@id="price-'.$item->id.'"]/@value)'));
        $this->assertSame('25.00', $document->evaluate('string(//input[@id="price-'.$other->id.'"]/@value)'));
        $this->assertSame('price-'.$item->id.'-hint price-'.$item->id.'-error', $document->evaluate('string(//input[@id="price-'.$item->id.'"]/@aria-describedby)'));
        $this->assertSame(1, $document->query('//input[@name="price"][@aria-invalid="true"]')->length);
        $this->assertSame(1, $document->query('//details[@id="product-'.$item->id.'"][@open]')->length);
        $this->assertSame('pharmacy_discount', $document->evaluate('string(//select[@id="price-mode-'.$item->id.'"]/option[@selected]/@value)'));
        $this->assertSame('0', $document->evaluate('string(//select[@id="in-stock-'.$item->id.'"]/option[@selected]/@value)'));
        $this->assertSame('15.00', $item->fresh()->price);
    }

    #[TestWith(['manual'])]
    #[TestWith(['copy'])]
    public function test_failed_addition_reopens_only_the_submitted_disclosure(string $form): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $url = route('promotions.edit', $promotion);

        $this->actingAs($supplier)->from($url)->post(route('promotions.items.store', $promotion), [
            ...$this->itemData(), '_promotion_form' => $form, 'price' => '-2', 'minimum_quantity' => 4,
        ])->assertRedirect();
        $document = $this->document($this->get($url));

        $this->assertSame(1, $document->query('//details[@open]')->length);
        $this->assertSame(1, $document->query('//details[@open]//input[@name="_promotion_form"][@value="'.$form.'"]')->length);
        $this->assertSame(0, $promotion->items()->count());
    }

    public function test_information_errors_and_restored_input_do_not_leak_into_product_forms(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create(['title' => 'Сохранённая акция']);
        $item = PromotionItem::factory()->for($promotion)->create(['name' => 'Сохранённый товар']);
        $url = route('promotions.edit', $promotion);

        $this->actingAs($supplier)->from($url)->patch(route('promotions.update', $promotion), [
            '_promotion_form' => 'information', 'organization_id' => $supplier->organization_id, 'title' => '', 'name' => 'Чужой товар',
            'starts_on' => '2026-11-10', 'ends_on' => '2026-11-01', 'description' => 'Правки описания',
        ])->assertRedirect();
        $document = $this->document($this->get($url)->assertSee('Правки описания')->assertDontSee('Чужой товар'));

        $this->assertSame('Сохранённый товар', $document->evaluate('string(//input[@id="name-'.$item->id.'"]/@value)'));
        $this->assertSame('ends_on-error', $document->evaluate('string(//input[@id="ends_on"]/@aria-describedby)'));
        $this->assertSame('Сохранённая акция', $promotion->fresh()->title);
    }

    public function test_material_failure_links_errors_only_to_the_affected_batch_control(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $url = route('promotions.edit', $promotion);

        $this->actingAs($supplier)->from($url)->post(route('promotions.attachments.store', $promotion), ['materials' => [UploadedFile::fake()->createWithContent('bad.svg', '<svg></svg>')]])
            ->assertRedirect();
        $document = $this->document($this->get($url));

        $this->assertSame('material-files-hint material-files-error', $document->evaluate('string(//input[@id="material-files"]/@aria-describedby)'));
        $this->assertSame(0, $document->query('//input[@name="poster" or @name="logo"][@aria-invalid]')->length);
        $this->assertSame(0, $promotion->attachments()->count());
    }

    public function test_copy_failure_keeps_selected_offer_outside_the_current_results(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $import = PriceListImport::factory()->for($supplier->organization, 'supplier')->create();
        $supplier->organization->update(['active_price_list_import_id' => $import->id]);
        $offer = Offer::factory()->for($supplier->organization)->create(['price_list_import_id' => $import->id, 'source_name' => 'Выбранный товар']);
        $url = route('promotions.edit', [$promotion, 'offer_search' => 'Нет совпадений']);

        $this->actingAs($supplier)->from($url)->post(route('promotions.items.store', $promotion), [
            ...$this->itemData(), '_promotion_form' => 'copy', 'source_offer_id' => $offer->id, 'price' => '-1',
        ])->assertRedirect();
        $document = $this->document($this->get($url)->assertSee('Выбранный товар'));

        $this->assertSame((string) $offer->id, $document->evaluate('string(//select[@name="source_offer_id"]/option[@selected]/@value)'));
        $this->assertSame(1, $document->query('//input[@id="price-copy"][@aria-invalid="true"]')->length);
    }

    public function test_offer_search_matches_source_and_product_names_and_preserves_pagination_with_company_isolation(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $import = PriceListImport::factory()->for($supplier->organization, 'supplier')->create();
        $supplier->organization->update(['active_price_list_import_id' => $import->id]);
        Offer::factory()->count(31)->for($supplier->organization)->sequence(fn ($sequence) => ['source_name' => sprintf('Needle %02d', $sequence->index)])
            ->create(['price_list_import_id' => $import->id]);
        $byMedicine = Offer::factory()->for($supplier->organization)->for(Medicine::factory()->state(['name' => 'Needle medicine']))
            ->create(['price_list_import_id' => $import->id, 'source_name' => 'ZZ product']);
        Offer::factory()->for($supplier->organization)->create(['price_list_import_id' => PriceListImport::factory()->for($supplier->organization, 'supplier')->create()->id, 'source_name' => 'Needle obsolete']);
        $other = User::factory()->wholesaler()->create();
        $otherImport = PriceListImport::factory()->for($other->organization, 'supplier')->create();
        $other->organization->update(['active_price_list_import_id' => $otherImport->id]);
        Offer::factory()->for($other->organization)->create(['price_list_import_id' => $otherImport->id, 'source_name' => 'Needle private']);

        $response = $this->actingAs($supplier)->get(route('promotions.edit', [$promotion, 'offer_search' => ' Needle ']));

        $response->assertViewHas('offers', fn ($offers) => $offers->total() === 32 && $offers->count() === 30)
            ->assertSee('offer_search=Needle', false)->assertSee('offers_page=2', false)->assertSee('#copy-products', false)
            ->assertDontSee('Needle obsolete')->assertDontSee('Needle private');
        $this->get(route('promotions.edit', [$promotion, 'offer_search' => 'Needle', 'offers_page' => 2]))
            ->assertViewHas('offers', fn ($offers) => $offers->count() === 2 && $offers->contains('id', $byMedicine->id));
        $this->get(route('promotions.edit', [$promotion, 'offer_search' => 'Missing']))->assertSee('Товары не найдены');
        $this->actingAs($other)->get(route('promotions.edit', $promotion))->assertForbidden();
    }

    #[TestWith([false, 'draft', true, false, false])]
    #[TestWith([false, 'pending_review', false, true, false])]
    #[TestWith([false, 'published', false, false, false])]
    #[TestWith([true, 'draft', true, false, true])]
    #[TestWith([true, 'pending_review', false, true, true])]
    #[TestWith([true, 'published', false, false, false])]
    public function test_editor_actions_follow_role_and_status(bool $admin, string $status, bool $submit, bool $withdraw, bool $publish): void
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($admin ? Organization::factory()->wholesaler() : $user->organization)->create(['status' => PromotionStatus::from($status)]);

        $document = $this->document($this->actingAs($user)->get(route('promotions.edit', $promotion)));

        foreach (['submit' => $submit, 'withdraw' => $withdraw, 'publish' => $publish] as $action => $expected) {
            $this->assertSame((int) $expected, $document->query('//button[@name="action"][@value="'.$action.'"]')->length);
        }
        $this->assertSame((int) ($admin || $status !== 'pending_review'), $document->query('//input[@name="_promotion_form"][@value="information"]/following-sibling::fieldset[not(@disabled)]')->length);
        $this->assertSame((int) ($admin && $status === 'pending_review'), $document->query('//textarea[@name="comment"]')->length);
    }

    public function test_product_and_material_operations_return_to_their_sections(): void
    {
        Storage::fake('local');
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $url = route('promotions.edit', [$promotion, 'offer_search' => 'test']);

        $this->actingAs($supplier)->from($url)->post(route('promotions.items.store', $promotion), $this->itemData())->assertRedirect($url.'#products');
        $item = $promotion->items()->sole();
        $this->patch(route('promotions.items.update', [$promotion, $item]), [...$this->itemData(), 'price' => '30.00'])->assertRedirect($url.'#products');
        $this->assertSame('30.00', $item->fresh()->price);
        $this->delete(route('promotions.items.destroy', [$promotion, $item]))->assertRedirect($url.'#products');
        $this->assertSoftDeleted($item);
        $this->post(route('promotions.attachments.store', $promotion), ['role' => 'banner', 'file' => UploadedFile::fake()->image('poster.png')])->assertRedirect($url.'#materials');
        $attachment = $promotion->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $this->delete(route('promotions.attachments.destroy', $attachment))->assertRedirect($url.'#materials');
        $this->assertModelMissing($attachment);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_materials_show_filename_role_type_and_size_with_escaped_content(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        PromotionItem::factory()->count(3)->for($promotion)->sequence(
            ['name' => 'Амоксициллин 500 мг, капсулы', 'price' => '120.50'],
            ['name' => 'Парацетамол 500 мг', 'price' => null],
            ['name' => 'Аскорбиновая кислота', 'price' => '25.00', 'in_stock' => false],
        )->create();
        $filename = str_repeat('Подробные_условия_', 12).'.pdf';
        PromotionAttachment::factory()->for($promotion)->create(['original_name' => $filename, 'size' => 1572864]);
        PromotionAttachment::factory()->for($promotion)->create(['role' => 'logo', 'original_name' => '<script>logo</script>.png', 'mime_type' => 'image/png', 'size' => 1024]);

        $response = $this->actingAs($supplier)->get(route('promotions.edit', $promotion))
            ->assertSee($filename)->assertSee('Материал · PDF · 1,5 МБ')->assertSee('Логотип компании · PNG · 1,0 КБ')
            ->assertSee('&lt;script&gt;logo&lt;/script&gt;.png', false)->assertDontSee('<script>logo</script>.png', false);
        $document = $this->document($response);
        $this->assertSame(0, $document->query('//*[@id = preceding::*/@id]')->length);
        $this->assertSame(2, $document->query('//input[@type="file"][@multiple]')->length);
        $this->assertSame(1, $document->query('//input[@name="logo"][not(@multiple)]')->length);
        $this->assertSame(1, $document->query('//button[text()="Загрузить все файлы"]')->length);
    }

    public function test_moderation_errors_link_to_the_comment_and_keep_the_decision_pending(): void
    {
        $promotion = Promotion::factory()->create(['status' => PromotionStatus::PendingReview]);
        $url = route('promotions.edit', $promotion);

        $this->actingAs(User::factory()->admin()->create())->from($url)
            ->post(route('promotions.transition', $promotion), ['action' => 'revise', 'comment' => ''])->assertRedirect($url);
        $document = $this->document($this->get($url)->assertSee('Укажите причину решения.'));

        $this->assertSame('comment-error', $document->evaluate('string(//textarea[@name="comment"]/@aria-describedby)'));
        $this->assertSame(PromotionStatus::PendingReview, $promotion->fresh()->status);
    }

    public function test_creation_shows_section_guidance_before_the_draft_exists(): void
    {
        $response = $this->actingAs(User::factory()->wholesaler()->create())->get(route('promotions.create'))
            ->assertSee('Сохранить информацию')->assertSee('Сначала сохраните информацию')->assertSee('Материалы доступны после сохранения');
        $this->assertSame(1, $this->document($response)->query('//form[@data-promotion-form]')->length);
    }
}
