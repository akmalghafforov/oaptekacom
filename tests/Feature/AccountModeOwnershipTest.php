<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountModeOwnershipTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['account-modes.enabled' => true]);
    }

    public function test_rebinding_an_assignment_rejects_forms_for_the_previous_organization(): void
    {
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $version = session('account_mode.version');
        $replacement = Organization::factory()->wholesaler()->create();
        $user->forceFill(['organization_id' => $replacement->id])->save();
        $this->patch(route('profile.organization.update'), ['name' => 'Old form', '_mode_version' => $version])->assertStatus(409);
        $this->assertNotSame($version, session('account_mode.version'));
        $this->assertNotSame('Old form', $replacement->fresh()->name);
    }

    public function test_ordinary_pharmacy_requires_an_unexpired_subscription(): void
    {
        $this->freezeTime();
        $user = User::factory()->pharmacy()->create();
        $subscription = Subscription::factory()->for($user)->create(['ends_on' => now('Asia/Dushanbe')->subDay()->toDateString()]);
        $this->actingAs($user)->get(route('catalog'))->assertRedirect(route('subscription.create'));
        $subscription->update(['ends_on' => now('Asia/Dushanbe')->addDay()->toDateString()]);
        $this->get(route('catalog'))->assertOk();
    }

    public function test_pharmacy_mode_preserves_user_owned_archives_and_refuses_foreign_snapshots(): void
    {
        $user = User::factory()->wholesaler()->create();
        $own = SupplierRequest::factory()->create(['user_id' => $user->id, 'buyer_organization_id' => $user->organization_id]);
        $foreign = SupplierRequest::factory()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => session('account_mode.version')])->assertRedirect(route('catalog'));
        $this->get(route('supplier-requests.show', $own))->assertOk();
        $this->get(route('supplier-requests.show', $foreign))->assertNotFound();
        $this->assertSame($user->organization_id, $own->fresh()->buyer_organization_id);
    }

    public function test_receipts_are_scoped_to_the_selected_organization_even_for_an_admin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('receipts/test.png', 'receipt');
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = $user->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $own = PaymentRequest::create(['organization_id' => $pharmacy->id, 'user_id' => $user->id, 'days' => 10, 'amount' => 10, 'receipt_path' => 'receipts/test.png']);
        $foreignUser = User::factory()->pharmacy()->create();
        $foreign = PaymentRequest::create(['organization_id' => $foreignUser->organization_id, 'user_id' => $foreignUser->id, 'days' => 10, 'amount' => 10, 'receipt_path' => 'receipts/test.png']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => session('account_mode.version')])->assertRedirect(route('catalog'));
        $this->get(route('payment-requests.receipt', $own))->assertOk();
        $this->get(route('payment-requests.receipt', $foreign))->assertForbidden();
    }

    public function test_directory_controls_cannot_block_admin_identities_or_secondary_members(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = $admin->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $this->actingAs($admin)->get(route('admin.pharmacies.show', $pharmacy))->assertOk()->assertSee($admin->name);
        $this->patch(route('admin.pharmacies.accounts.update', [$pharmacy, $admin]), ['name' => $admin->name, 'email' => $admin->email, 'is_blocked' => true, '_mode_version' => session('account_mode.version')])->assertForbidden();
        $this->assertFalse($admin->fresh()->is_blocked);
    }
}
