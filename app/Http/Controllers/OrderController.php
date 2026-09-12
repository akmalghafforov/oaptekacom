<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $orders = Order::query()
            ->with(['supplier', 'buyer'])
            ->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->where(fn (Builder $organizationQuery): Builder => $organizationQuery->where('buyer_organization_id', $user->organization_id)->orWhere('supplier_organization_id', $user->organization_id)))
            ->latest()
            ->paginate();

        return view('orders.index', compact('orders'));
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('orders.show', compact('order'));
    }

    public function status(Order $order, Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $order);
        $validated = $request->validate(['status' => 'required|in:received,confirmed,partially_confirmed,preparing,ready,delivering,received_by_customer,cancelled']);
        $before = $order->only('status');
        $order->update($validated);
        $auditLogger->log('order.status_changed', $order, $before, $order->only('status'));

        return back()->with('success', 'Статус обновлён.');
    }
}
