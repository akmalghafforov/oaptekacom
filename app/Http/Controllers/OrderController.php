<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $orders = Order::with(['supplier', 'buyer'])->when(! $u->isAdmin(), fn ($q) => $q->where(fn ($orders) => $orders->where('buyer_organization_id', $u->organization_id)->orWhere('supplier_organization_id', $u->organization_id)))->latest()->paginate();

        return view('orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        return view('orders.show', compact('order'));
    }

    public function status(Order $order, Request $r)
    {
        $this->authorize('update', $order);
        $d = $r->validate(['status' => 'required|in:received,confirmed,partially_confirmed,preparing,ready,delivering,received_by_customer,cancelled']);
        $before = $order->only('status');
        $order->update($d);
        app(AuditLogger::class)->log('order.status_changed', $order, $before, $order->only('status'));

        return back()->with('success', 'Статус обновлён.');
    }
}
