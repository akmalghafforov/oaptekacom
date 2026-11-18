<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\SupplierEmailDelivery;
use Illuminate\Support\Facades\DB;

class SupplierEmailExclusions
{
    public function seedApprovedHistory(): void
    {
        $counts = ['Мижгона' => 28, 'Канзи Шифо' => 27, 'Форс' => 24, 'Фарм-Плюс' => 17, 'Сино-Фарм' => 16, 'НурФарма' => 15, 'Осон-Фарм' => 14, 'Соф Фарм' => 9, 'Вориси Сино' => 9, 'Дармон' => 6, 'Фарм Мед ул.Мир' => 4];
        foreach ($counts as $name => $count) {
            $supplier = Organization::where('type', OrganizationType::Wholesaler)->get()->first(fn (Organization $supplier): bool => mb_strtolower($supplier->name) === mb_strtolower($name));
            if (! $supplier) {
                continue;
            }
            DB::transaction(function () use ($supplier, $count): void {
                $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                if ($supplier->external_fetch_overridden) {
                    return;
                }
                $before = $supplier->only(['external_fetch_enabled', 'external_fetch_exclusion_reason']);
                $evidence = $supplier->external_fetch_evidence ?? [];
                $evidence['approved_initial_history'] = ['start' => '2026-10-18', 'end' => '2026-11-17', 'deliveries' => $count];
                $supplier->update(['external_fetch_enabled' => false, 'external_fetch_exclusion_reason' => 'regular_email', 'external_fetch_evidence' => $evidence]);
                app(AuditLogger::class)->log('supplier_external_fetch.seeded', $supplier, $before, $supplier->only(['external_fetch_enabled', 'external_fetch_exclusion_reason', 'external_fetch_evidence']));
            });
        }
    }

    public function assess(bool $seed = false): void
    {
        $end = now('Asia/Dushanbe')->startOfMonth();
        $start = $end->copy()->subMonth();
        $counts = SupplierEmailDelivery::where('received_at', '>=', $start)->where('received_at', '<', $end)
            ->selectRaw('supplier_organization_id, count(*) as deliveries')->groupBy('supplier_organization_id')->pluck('deliveries', 'supplier_organization_id');
        Organization::where('type', OrganizationType::Wholesaler)->eachById(function (Organization $supplier) use ($start, $end, $counts, $seed): void {
            DB::transaction(function () use ($supplier, $start, $end, $counts, $seed): void {
                $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                $count = (int) ($counts[$supplier->id] ?? 0);
                $evidence = array_intersect_key($supplier->external_fetch_evidence ?? [], ['approved_initial_history' => true]) + ['start' => $start->toDateString(), 'end_exclusive' => $end->toDateString(), 'deliveries' => $count,
                    'recommendation' => $count >= 3 ? 'regular_email' : 'insufficient_evidence'];
                $changes = ['external_fetch_evidence' => $evidence, 'external_fetch_assessed_at' => now()];
                if ($seed && ! $supplier->external_fetch_overridden && $count >= 3) {
                    $changes += ['external_fetch_enabled' => false, 'external_fetch_exclusion_reason' => 'regular_email'];
                }
                $supplier->update($changes);
            }, 3);
        });
    }
}
