<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Jobs\CommitPriceListImport;
use App\Jobs\MaterializePriceListImportChunk;
use App\Jobs\PreparePriceListImport;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RolloutAutomaticPriceListImportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_releases_legacy_imports_and_is_idempotent(): void
    {
        Queue::fake([PreparePriceListImport::class, MaterializePriceListImportChunk::class, CommitPriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $duplicate = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::AwaitingDuplicateConfirmation]);
        $preview = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview]);
        $row = PriceListImportRow::factory()->for($preview, 'import')->create([
            'categorization_status' => 'review_required',
            'disposition' => PriceListRowDisposition::Warning,
            'planned_action' => PriceListRowAction::Create,
            'warnings' => ['Категории товара требуют проверки.'],
        ]);

        $this->artisan('price-list-imports:rollout-automatic')->assertSuccessful();
        $this->artisan('price-list-imports:rollout-automatic')->assertSuccessful();

        $this->assertSame(PriceListImportStatus::Pending, $duplicate->fresh()->status);
        $this->assertSame('attention_needed', $row->fresh()->categorization_status);
        $this->assertSame(PriceListRowDisposition::Valid, $row->fresh()->disposition);
        $this->assertSame([], $row->fresh()->warnings);
        Queue::assertPushed(PreparePriceListImport::class, 1);
        Queue::assertPushed(MaterializePriceListImportChunk::class, 2);
    }
}
