<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function create()
    {
        abort_unless(auth()->user()->can('create', PaymentRequest::class), 403);

        return view('payments.create');
    }

    public function store(Request $r)
    {
        $this->authorize('create', PaymentRequest::class);
        $d = $r->validate(['days' => 'required|integer|min:1|max:365', 'receipt' => 'nullable|file|max:10240']);
        $path = $r->file('receipt')?->store('receipts', 'local');
        PaymentRequest::create(['organization_id' => $r->user()->organization_id, 'user_id' => $r->user()->id, 'days' => $d['days'], 'amount' => $d['days'], 'receipt_path' => $path]);

        return redirect()->route('dashboard')->with('success', 'Запрос на оплату отправлен.');
    }
}
