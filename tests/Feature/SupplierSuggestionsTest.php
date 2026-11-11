<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ExternalSupplierDirectory;
use App\Services\IcorpClient;
use App\Services\SupplierMappingApproval;
use App\Services\SupplierSuggestionMatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierSuggestionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['  ООО «ОСОН—ФАРМ» ', 'осон фарм', 100])]
    #[TestWith(['Аличон — ОсонФарм(Оптовый)', 'Аличон ОСОН фарм', 100])]
    #[TestWith(['ЧДММ Дорухонаи Осон Фарм Душанбе', 'Фарм Осон', 100])]
    #[TestWith(['Ёлка-Фарм', 'елка фарм', 100])]
    #[TestWith(['Осонфарм', 'Осонферм', 87.5])]
    #[TestWith(['АБ', 'аб', 100])]
    public function test_unicode_names_spacing_descriptors_and_reordered_tokens(string $left, string $right, float $expected): void
    {
        $matcher = app(SupplierSuggestionMatcher::class);
        $matcher->useCities(['Душанбе']);
        $evidence = $matcher->compare($matcher->prepare(new ExternalSupplierPartner(['name' => $left, 'phones' => []])), $matcher->prepare(new Organization(['name' => $right])));

        $this->assertNotNull($evidence);
        $this->assertEqualsWithDelta($expected, $evidence['similarity'], 0.01);
    }

    #[TestWith(['Аптека ООО Душанбе', 'Дорухонаи ЧДММ Душанбе'])]
    #[TestWith(['Аба', 'Або'])]
    #[TestWith(['АБВ', 'АБВГ'])]
    #[TestWith(['Душанбе', 'Душанбе'])]
    #[TestWith(['Медицина', 'Совсем другое'])]
    public function test_generic_city_only_weak_and_short_fuzzy_names_do_not_qualify(string $left, string $right): void
    {
        $matcher = app(SupplierSuggestionMatcher::class);
        $matcher->useCities(['Душанбе']);
        $this->assertNull($matcher->compare($matcher->prepare(new Organization(['name' => $left])), $matcher->prepare(new Organization(['name' => $right]))));
    }

    public function test_city_ranking_phone_evidence_and_stable_ties(): void
    {
        $partner = ExternalSupplierPartner::factory()->create(['name' => 'Осонфарм Душанбе', 'metadata' => [], 'phones' => ['927430570']]);
        $unknown = Organization::factory()->wholesaler()->create(['name' => 'Осонфарм', 'city' => null, 'phone' => null]);
        $conflict = Organization::factory()->wholesaler()->create(['name' => 'Осонфарм', 'city' => 'Худжанд', 'phone' => null]);
        $matching = Organization::factory()->wholesaler()->create(['name' => 'Осонфарм', 'city' => 'Душанбе', 'phone' => null]);
        $phone = Organization::factory()->wholesaler()->create(['name' => 'Другая фирма', 'city' => null, 'phone' => '+992927430570']);
        $candidates = app(ExternalSupplierDirectory::class)->suggestions($partner);

        $this->assertSame([$matching->id, $unknown->id, $phone->id, $conflict->id], array_column($candidates, 'id'));
        $this->assertSame([110.0, 100.0, 100, 80.0], array_column($candidates, 'score'));
        $this->assertContains('Город из названия: душанбе', $candidates[0]['evidence']);
        $this->assertContains('Город неизвестен', $candidates[1]['evidence']);
        $this->assertContains('Совпадает телефон', $candidates[2]['evidence']);
        $this->assertContains('Разные города: душанбе / худжанд', $candidates[3]['evidence']);
        $this->assertTrue(app(SupplierSuggestionMatcher::class)->ambiguous($candidates));
        $matcher = app(SupplierSuggestionMatcher::class);
        $prepared = $matcher->prepare(new Organization(['name' => 'Осонфарм Душанбе', 'city' => 'Худжанд']));
        $this->assertSame('худжанд', $prepared['city']);
        $this->assertSame('Город: худжанд', $prepared['city_label']);
    }

    public function test_suggestions_are_read_only_keep_canonical_rows_and_find_other_services_outside_filters(): void
    {
        Http::preventStrayRequests();
        $admin = $this->admin();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Аличон Осонфарм', 'city' => 'Душанбе']);
        $left = ExternalSupplierPartner::factory()->create(['source' => 'icorp', 'name' => 'Аличон — ОсонФарм(Оптовый)', 'phones' => []]);
        $right = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Аличон ОСОН фарм', 'phones' => []]);
        $before = [$left->fresh()->toArray(), $right->fresh()->toArray(), Organization::count(), AuditEvent::count()];
        $this->actingAs($admin)->get(route('admin.supplier-sync.index', ['filter' => 'suggested', 'edit' => $left->id]))
            ->assertOk()->assertSee('Предположение')->assertSee('Проверить предположение')->assertSee('Город неизвестен')
            ->assertViewHas('partners', fn ($rows): bool => $rows->total() === 2)
            ->assertSee('Аличон ОСОН фарм')->assertSee('Аличон Осонфарм');
        $this->get(route('admin.supplier-sync.index', ['service' => 'icorp', 'q' => 'Аличон', 'filter' => 'suggested']))
            ->assertOk()->assertSee($right->name)->assertViewHas('partners', fn ($rows): bool => $rows->total() === 1);
        $this->assertSame($before, [$left->fresh()->toArray(), $right->fresh()->toArray(), Organization::count(), AuditEvent::count()]);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
        Http::assertNothingSent();
    }

    public function test_confirmed_values_take_precedence_and_ambiguous_external_candidates_start_unselected(): void
    {
        $admin = $this->admin();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Утвержденный поставщик']);
        $left = ExternalSupplierPartner::factory()->create(['source' => 'icorp', 'name' => 'Осонфарм', 'supplier_organization_id' => $supplier->id]);
        $first = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Осонфарм']);
        $second = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Осонфарм']);
        $response = $this->actingAs($admin)->get(route('admin.supplier-sync.index', ['filter' => 'ambiguous', 'edit' => $left->id]));
        $response->assertOk()->assertSee('Утвержденный поставщик')->assertSee('Есть несколько близких кандидатов')
            ->assertViewHas('partners', fn ($rows): bool => $rows->total() === 1);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//select[@id="approve-'.$left->id.'-pricelist_tj"]/option[@selected]')->length);
        $first->update(['supplier_organization_id' => $supplier->id]);
        $this->get(route('admin.supplier-sync.index', ['filter' => 'confirmed']))->assertOk()
            ->assertViewHas('reviewSuggestions', fn (array $suggestions): bool => $suggestions[$left->id]['external']['pricelist_tj'] === [])
            ->assertViewHas('partners', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_suggestion_filters_paginate_canonical_rows(): void
    {
        $admin = $this->admin();
        Organization::factory()->wholesaler()->create(['name' => 'Общий бренд']);
        ExternalSupplierPartner::factory()->count(26)->create(['source' => 'icorp', 'name' => 'Общий бренд']);
        $this->actingAs($admin)->get(route('admin.supplier-sync.index', ['filter' => 'suggested', 'page' => 2]))
            ->assertOk()->assertViewHas('partners', fn ($rows): bool => $rows->total() === 26 && $rows->count() === 1)
            ->assertSee('filter=suggested', false);
    }

    public function test_combined_approval_is_audited_and_preserves_configuration_profiles_and_priorities(): void
    {
        $admin = $this->admin();
        $supplier = Organization::factory()->wholesaler()->create();
        $config = SupplierSynchronization::factory()->create(['supplier_organization_id' => $supplier->id, 'automatic_enabled' => false]);
        $partners = $this->pair();
        $before = $config->refresh()->only(['sources', 'automatic_enabled', 'settings_confirmed']);
        $this->actingAs($admin)->post(route('admin.supplier-sync.approve'), $this->approvalData($partners, $supplier))->assertRedirectToRoute('admin.supplier-sync.show', $supplier);
        foreach ($partners as $partner) {
            $this->assertSame($supplier->id, $partner->refresh()->supplier_organization_id);
            $this->assertSame(2, $partner->profile_version);
            $this->assertFalse($partner->profile_active);
            $this->assertDatabaseHas('audit_events', ['event' => 'supplier_mapping.confirmed', 'subject_id' => $partner->id]);
        }
        $this->assertSame($before, $config->refresh()->only(['sources', 'automatic_enabled', 'settings_confirmed']));
        $this->assertSame(2, $config->version);
        $this->assertNull($supplier->fresh()->active_price_list_import_id);
    }

    public function test_new_supplier_requires_explicit_mode_and_supports_partial_source_selection(): void
    {
        $this->actingAs($this->admin());
        $partners = $this->pair();
        $data = $this->approvalData($partners);
        unset($data['mapping_mode']);
        $this->postJson(route('admin.supplier-sync.approve'), $data)->assertUnprocessable()->assertJsonValidationErrors('mapping_mode');
        $data['mapping_mode'] = 'new';
        $data['partner_ids'] = ['icorp' => $partners[0]->id, 'pricelist_tj' => ''];
        $this->post(route('admin.supplier-sync.approve'), $data)->assertRedirect();
        $supplier = $partners[0]->fresh()->supplier;
        $this->assertSame('Проверенный новый', $supplier->name);
        $this->assertSame('Душанбе', $supplier->city);
        $this->assertNull($partners[1]->fresh()->supplier_organization_id);
        $this->assertFalse(SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail()->automatic_enabled);
    }

    public function test_approval_requires_administrator_and_confirmation_and_rejects_duplicate_services(): void
    {
        $partners = $this->pair();
        $data = $this->approvalData($partners);
        $this->postJson(route('admin.supplier-sync.approve'), $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('admin.supplier-sync.approve'), $data)->assertForbidden();
        $this->actingAs($this->admin());
        $data['confirmed'] = '';
        $this->postJson(route('admin.supplier-sync.approve'), $data)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $partners[1]->update(['source' => 'icorp']);
        $duplicates = $this->approvalData($partners);
        $duplicates['partner_ids'] = array_column(array_map(fn ($record) => ['id' => $record->id], $partners), 'id');
        $this->postJson(route('admin.supplier-sync.approve'), $duplicates)->assertUnprocessable()->assertJsonValidationErrors('partner_ids');
        $this->assertDatabaseMissing('organizations', ['name' => 'Проверенный новый']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'organization.supplier_created']);
    }

    #[TestWith(['name'])]
    #[TestWith(['city'])]
    #[TestWith(['phone'])]
    #[TestWith(['profile'])]
    #[TestWith(['mapping'])]
    #[TestWith(['configuration'])]
    #[TestWith(['supplier'])]
    public function test_stale_review_rolls_back_every_link(string $change): void
    {
        $this->actingAs($this->admin());
        $supplier = Organization::factory()->wholesaler()->create();
        $config = SupplierSynchronization::factory()->create(['supplier_organization_id' => $supplier->id]);
        $partners = $this->pair();
        $data = $this->approvalData($partners, $supplier);
        match ($change) {
            'name' => $partners[1]->update(['name' => 'Изменилось название']),
            'city' => $partners[1]->update(['metadata' => ['city' => 'Худжанд']]),
            'phone' => $partners[1]->update(['phones' => ['927430570']]),
            'profile' => $partners[1]->update(['profile_version' => 2]),
            'mapping' => $partners[1]->update(['supplier_organization_id' => Organization::factory()->wholesaler()->create()->id]),
            'configuration' => $config->increment('version'),
            'supplier' => $supplier->update(['city' => 'Изменился город']),
        };
        $this->postJson(route('admin.supplier-sync.approve'), $data)->assertUnprocessable();
        $this->assertNull($partners[0]->fresh()->supplier_organization_id);
        $this->assertSame(1, $partners[0]->profile_version);
        $this->assertDatabaseMissing('audit_events', ['event' => 'supplier_mapping.confirmed']);
    }

    public function test_conflicting_mapping_is_never_overwritten_even_with_current_evidence(): void
    {
        $this->actingAs($this->admin());
        $supplier = Organization::factory()->wholesaler()->create();
        $partners = $this->pair();
        $other = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'supplier_organization_id' => $supplier->id]);
        $this->postJson(route('admin.supplier-sync.approve'), $this->approvalData($partners, $supplier))->assertUnprocessable()->assertJsonValidationErrors('partner_ids.pricelist_tj');
        $this->assertNull($partners[0]->fresh()->supplier_organization_id);
        $this->assertSame($supplier->id, $other->fresh()->supplier_organization_id);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
        $partners[1]->update(['supplier_organization_id' => Organization::factory()->wholesaler()->create()->id]);
        $this->postJson(route('admin.supplier-sync.approve'), $this->approvalData($partners))->assertUnprocessable();
        $this->assertDatabaseMissing('organizations', ['name' => 'Проверенный новый']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'organization.supplier_created']);
    }

    public function test_audit_failure_rolls_back_both_links_creation_and_configuration(): void
    {
        $this->actingAs($this->admin());
        $partners = $this->pair();
        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('log')->with('organization.supplier_created', \Mockery::any(), \Mockery::any(), \Mockery::any())->once();
            $mock->shouldReceive('log')->with('supplier_mapping.confirmed', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->passthru();
            $mock->shouldReceive('log')->with('supplier_mapping.confirmed', \Mockery::any(), \Mockery::any(), \Mockery::any())->once()->andThrow(new \RuntimeException('Audit failed'));
        });
        $this->postJson(route('admin.supplier-sync.approve'), $this->approvalData($partners))->assertStatus(500);
        $this->assertNull($partners[0]->fresh()->supplier_organization_id);
        $this->assertNull($partners[1]->fresh()->supplier_organization_id);
        $this->assertDatabaseMissing('organizations', ['name' => 'Проверенный новый']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'supplier_mapping.confirmed']);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
    }

    public function test_validation_restores_only_the_submitted_review_and_retains_filters_and_selections(): void
    {
        $this->actingAs($this->admin());
        $partners = $this->pair();
        $data = $this->approvalData($partners);
        $data['_sync_form'] = 'approve-'.$partners[0]->id;
        $data['confirmed'] = '';
        $data['name'] = 'Название после ошибки';
        $url = route('admin.supplier-sync.index', ['q' => 'Осон', 'service' => 'icorp', 'filter' => 'suggested', 'edit' => $partners[0]->id]);
        $this->from($url)->post(route('admin.supplier-sync.approve'), $data)->assertRedirect($url);
        $response = $this->get($url)->assertOk()->assertSee('value="Название после ошибки"', false)
            ->assertSee('id="approve-'.$partners[0]->id.'-confirmed-error"', false)
            ->assertDontSee('id="mapping-'.$partners[0]->id.'-confirmed-error"', false)
            ->assertSee('name="service"', false)->assertSee('name="filter"', false);
        $this->assertMatchesRegularExpression('/id="mapping-'.$partners[0]->id.'"\s+open/', $response->getContent());
        $this->assertMatchesRegularExpression('/value="'.$partners[1]->id.'"\s+selected/', $response->getContent());
    }

    public function test_stale_review_remains_open_when_changed_records_no_longer_match_filters(): void
    {
        $this->actingAs($this->admin());
        $partners = $this->pair();
        $data = $this->approvalData($partners);
        $data['_sync_form'] = 'approve-'.$partners[0]->id;
        $url = route('admin.supplier-sync.index', ['filter' => 'suggested', 'service' => 'icorp']);
        $partners[1]->update(['name' => 'Теперь совершенно другое название']);
        $this->from($url)->post(route('admin.supplier-sync.approve'), $data)->assertRedirect($url);
        $response = $this->get($url)->assertOk()->assertSee('Данные изменились после проверки')
            ->assertSee('Теперь совершенно другое название');
        $this->assertMatchesRegularExpression('/id="mapping-'.$partners[0]->id.'"\s+open/', $response->getContent());
        $this->assertMatchesRegularExpression('/value="'.$partners[1]->id.'"\s+selected/', $response->getContent());
        $this->assertNull($partners[0]->fresh()->supplier_organization_id);
    }

    public function test_directory_readers_preserve_city_and_omitted_city_does_not_erase_metadata(): void
    {
        Storage::fake('local');
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test', 'external-prices.icorp.phone' => '927430570']);
        Storage::disk('local')->put('external-prices/sessions/icorp-'.hash('sha256', '927430570').'.encrypted', Crypt::encryptString(json_encode(['cookies' => [], 'user_agent' => 'test'])));
        Http::preventStrayRequests();
        Http::fake([
            'icorp.tech/listpartners' => Http::response('<article data-partner-id="1" data-city="Душанбе"><h3>Осонфарм</h3></article>'),
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'test']),
            'pricelist-tj.com/api/companies?*' => Http::sequence()
                ->push(['companies' => [['id' => 'one', 'name' => 'Осонфарм', 'city' => 'Худжанд', 'verified' => true]], 'pagination' => ['page' => 1, 'total' => 1, 'totalPages' => 1]])
                ->push(['companies' => [['id' => 'one', 'name' => 'Осонфарм', 'verified' => true]], 'pagination' => ['page' => 1, 'total' => 1, 'totalPages' => 1]]),
        ]);
        $this->assertSame('Душанбе', app(IcorpClient::class)->partners()[0]['city']);
        $directory = app(ExternalSupplierDirectory::class);
        $directory->refresh('pricelist_tj');
        $partner = ExternalSupplierPartner::where('external_id', 'one')->firstOrFail();
        $this->assertSame('Худжанд', $partner->metadata['city']);
        $directory->refresh('pricelist_tj');
        $this->assertSame('Худжанд', $partner->fresh()->metadata['city']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/priceview/') || str_contains($request->url(), '/companies/one'));
    }

    public function test_rendered_review_can_approve_explicit_choices_with_multiple_city_warnings(): void
    {
        $this->actingAs($this->admin());
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Аличон Осонфарм', 'city' => 'Душанбе']);
        Organization::factory()->wholesaler()->create(['name' => 'Аличон Осонфарм', 'city' => null]);
        $left = ExternalSupplierPartner::factory()->create(['source' => 'icorp', 'name' => 'Аличон — ОсонФарм(Оптовый)', 'metadata' => ['city' => 'Душанбе']]);
        $right = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Аличон ОСОН фарм', 'metadata' => ['city' => 'Худжанд']]);
        ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Аличон ОСОН фарм', 'metadata' => ['city' => 'Худжанд']]);
        $response = $this->get(route('admin.supplier-sync.index', ['edit' => $left->id]))->assertOk()->assertSee('Разные города');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[input[@name="_sync_form" and @value="approve-'.$left->id.'"]]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('.//select[@name="supplier_organization_id"]/option[@selected]', $form)->length);
        $this->assertSame(0, $xpath->query('.//select[@name="partner_ids[pricelist_tj]"]/option[@selected]', $form)->length);
        $data = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $field) {
            $data[$field->getAttribute('name')] = $field->getAttribute('value');
        }
        $data += ['partner_ids' => ['icorp' => $left->id, 'pricelist_tj' => $right->id], 'mapping_mode' => 'existing', 'supplier_organization_id' => $supplier->id, 'confirmed' => 1];
        $this->post(route('admin.supplier-sync.approve'), $data)->assertRedirectToRoute('admin.supplier-sync.show', $supplier);
        $this->assertSame($supplier->id, $left->fresh()->supplier_organization_id);
        $this->assertSame($supplier->id, $right->fresh()->supplier_organization_id);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
    }

    /** @return list<ExternalSupplierPartner> */
    private function pair(): array
    {
        return [ExternalSupplierPartner::factory()->create(['source' => 'icorp', 'name' => 'Осонфарм']),
            ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj', 'name' => 'Осонфарм'])];
    }

    /** @param list<ExternalSupplierPartner> $partners @return array<string, mixed> */
    private function approvalData(array $partners, ?Organization $supplier = null): array
    {
        $approval = app(SupplierMappingApproval::class);
        $data = ['partner_ids' => [], 'partner_versions' => [], 'partner_evidence' => [], 'mapping_mode' => $supplier ? 'existing' : 'new',
            'name' => 'Проверенный новый', 'city' => 'Душанбе', 'confirmed' => 1];
        foreach ($partners as $index => $partner) {
            $partner->refresh();
            $data['partner_ids'][$partner->source] = $partner->id;
            $data['partner_versions'][$partner->id] = $partner->profile_version;
            $data['partner_evidence'][$partner->id] = $approval->fingerprint($partner);
        }
        if ($supplier) {
            $version = (int) (SupplierSynchronization::where('supplier_organization_id', $supplier->id)->value('version') ?? 0);
            $data['supplier_organization_id'] = $supplier->id;
            $data['supplier_versions'] = [$supplier->id => $version];
            $data['supplier_evidence'] = [$supplier->id => $approval->fingerprint($supplier, $version)];
        }

        return $data;
    }
}
