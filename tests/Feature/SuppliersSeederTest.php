<?php

namespace Tests\Feature;

use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SuppliersSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SuppliersSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_active_wholesale_otp_accounts_and_approved_sender_addresses(): void
    {
        $this->seed(SuppliersSeeder::class);

        $this->assertDatabaseCount('organizations', 50);
        $this->assertDatabaseCount('users', 50);
        $this->assertDatabaseCount('supplier_sender_addresses', 31);
        $this->assertSame(50, Organization::query()->where('type', 'wholesaler')->count());
        $this->assertSame(50, User::query()->where('role', UserRole::Wholesaler)->whereNotNull('organization_id')->count());
        $this->assertDatabaseHas('organizations', [
            'name' => 'Имдоди-Шифо №1',
            'city' => 'Истаравшан',
            'phone' => '+992505008282',
            'type' => 'wholesaler',
            'status' => 'active',
            'supplier_mode' => TradeMode::Supplier->value,
        ]);
        $this->assertDatabaseHas('users', [
            'name' => 'Имдоди-Шифо №1',
            'phone' => '+992505008282',
            'role' => UserRole::Wholesaler->value,
            'password' => null,
            'email' => null,
            'phone_verified_at' => null,
            'active_trade_mode' => TradeMode::Supplier->value,
        ]);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Дорухонаи Дармон',
            'phone' => '+992000900017',
            'type' => 'wholesaler',
        ]);
        $this->assertDatabaseHas('supplier_sender_addresses', [
            'email' => 'apteka.darmon@mail.ru',
            'normalized_email' => 'apteka.darmon@mail.ru',
        ]);

        $this->seed(SuppliersSeeder::class);

        $this->assertSame(50, Organization::query()->where('type', 'wholesaler')->count());
        $this->assertSame(50, User::query()->where('role', UserRole::Wholesaler)->whereNotNull('organization_id')->count());
        $this->assertDatabaseCount('supplier_sender_addresses', 31);
    }
}
