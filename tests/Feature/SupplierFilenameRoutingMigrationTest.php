<?php

namespace Tests\Feature;

use App\Models\GmailImportReceipt;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SupplierFilenameRoutingMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_migration_preserves_existing_addresses_profiles_and_terminal_message_receipts(): void
    {
        $profile = SupplierImportProfile::factory()->create();
        $address = SupplierSenderAddress::factory()->for($profile->supplier, 'supplier')->create();
        $receipt = GmailImportReceipt::factory()->create(['status' => 'completed', 'supplier_organization_id' => $profile->supplier_organization_id]);
        $migration = require database_path('migrations/2026_10_16_085443_add_supplier_filename_routing.php');
        $migration->down();
        $before = DB::table('gmail_import_receipts')->where('id', $receipt->id)->first();

        $migration->up();

        $after = (array) DB::table('gmail_import_receipts')->where('id', $receipt->id)->first();
        $this->assertSame('message', $after['receipt_scope']);
        $this->assertNull($after['parent_receipt_id']);
        unset($after['receipt_scope'], $after['parent_receipt_id']);
        $this->assertSame((array) $before, $after);
        $this->assertSame($address->fresh()->only(['supplier_organization_id', 'normalized_email', 'email']), $address->only(['supplier_organization_id', 'normalized_email', 'email']));
        $this->assertNull($profile->fresh()->attachment_filename_filter);
        $this->assertSame('completed', $receipt->fresh()->status);
    }

    #[TestWith(['shared_address'])]
    #[TestWith(['child_receipt'])]
    public function test_rollback_refuses_data_that_violates_the_previous_constraints(string $case): void
    {
        if ($case === 'shared_address') {
            SupplierSenderAddress::factory()->create(['normalized_email' => 'shared@example.com']);
            SupplierSenderAddress::factory()->create(['normalized_email' => 'shared@example.com']);
        } else {
            $parent = GmailImportReceipt::factory()->create();
            GmailImportReceipt::factory()->create(['mailbox_email' => $parent->mailbox_email, 'gmail_message_id' => $parent->gmail_message_id, 'receipt_scope' => 'supplier:1', 'parent_receipt_id' => $parent->id]);
        }
        $migration = require database_path('migrations/2026_10_16_085443_add_supplier_filename_routing.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse shared routing data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('shared sender addresses or supplier receipts', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('gmail_import_receipts', 'receipt_scope'));
        $this->assertTrue(Schema::hasColumn('supplier_import_profiles', 'attachment_filename_filter'));
        $this->assertSame(2, $case === 'shared_address' ? SupplierSenderAddress::count() : GmailImportReceipt::count());
    }
}
