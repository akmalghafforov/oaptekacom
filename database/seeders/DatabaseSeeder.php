<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $pharmacy = Organization::firstOrCreate(['phone' => '+992900000001'], ['name' => 'Тестовая аптека', 'type' => OrganizationType::Pharmacy, 'status' => 'active', 'subscription_until' => now()->addYear()]);
        $wholesaler = Organization::firstOrCreate(['phone' => '+992900000002'], ['name' => 'Тестовый поставщик', 'type' => OrganizationType::Wholesaler, 'status' => 'active', 'supplier_mode' => 'both']);

        User::updateOrCreate(['email' => 'pharmacy@example.test'], ['name' => 'Тестовая аптека', 'phone' => '+992900000001', 'password' => null, 'organization_id' => $pharmacy->id, 'role' => UserRole::Pharmacy, 'active_trade_mode' => TradeMode::Buyer]);
        User::updateOrCreate(['email' => 'wholesaler@example.test'], ['name' => 'Тестовый поставщик', 'phone' => '+992900000002', 'password' => 'password', 'organization_id' => $wholesaler->id, 'role' => UserRole::Wholesaler, 'active_trade_mode' => TradeMode::Supplier]);
        User::updateOrCreate(['email' => 'admin@example.test'], ['name' => 'Тестовый администратор', 'phone' => '+992900000003', 'password' => 'password', 'organization_id' => null, 'role' => UserRole::Admin, 'active_trade_mode' => null]);
    }
}
