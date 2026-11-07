<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\PromotionQualification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $orders = Order::query()
            ->with(['supplier', 'buyer'])
            ->when(! $user->isAdminMode(), fn (Builder $query): Builder => $user->canSupply() ? $query->where('supplier_organization_id', $user->activeOrganizationId()) : $query->whereIn('buyer_organization_id', $user->purchaseOrganizationIds()))
            ->latest()
            ->paginate();

        return view('orders.index', compact('orders'));
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('orders.show', compact('order'));
    }

    public function status(Order $order, Request $request, AuditLogger $auditLogger, PromotionQualification $qualification): RedirectResponse
    {
        $this->authorize('update', $order);
        $validated = $request->validate(['status' => 'required|in:received,confirmed,partially_confirmed,preparing,ready,delivering,received_by_customer,cancelled']);
        DB::transaction(function () use ($order, $validated, $auditLogger, $qualification): void {
            Organization::query()->whereKey($order->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $current = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($current->supplier_organization_id === $order->supplier_organization_id, 409);
            $this->authorize('update', $current);
            $before = $current->only('status');
            $current->update($validated);
            $qualification->recomputeForOrder($current);
            $auditLogger->log('order.status_changed', $current, $before, $current->only('status'));
        }, 3);

        return back()->with('success', 'Статус обновлён.');
    }
}
