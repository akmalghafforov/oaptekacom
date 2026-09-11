<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function create(): View
    {
        $user = auth()->user();

        return view('subscriptions.status', ['user' => $user, 'subscription' => $user->subscriptions()->where('status', SubscriptionStatus::Active)->latest()->first()]);
    }
}
