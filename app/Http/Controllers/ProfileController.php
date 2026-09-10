<?php

namespace App\Http\Controllers;

use App\Enums\TradeMode;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\AuditLogger;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile.edit', ['user' => request()->user(), 'organization' => request()->user()->organization]);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $before = $user->only('name', 'email', 'phone', 'theme');
        $data = $request->safe()->only(['name', 'email', 'phone', 'theme']);
        if ($request->filled('password')) {
            $data['password'] = $request->string('password')->toString();
            $data['password_change_required'] = false;
        }
        $user->forceFill($data)->save();
        app(AuditLogger::class)->log('profile.updated', $user, $before, $user->only('name', 'email', 'phone', 'theme'));

        return back()->with('success', 'Профиль сохранён.');
    }

    public function updateOrganization(UpdateOrganizationRequest $request)
    {
        $user = $request->user();
        $organization = $user->organization;
        abort_unless($organization, 403);
        $before = $organization->only('name', 'city', 'phone', 'minimum_order', 'delivery_conditions');
        $organization->update($request->safe()->only(['name', 'city', 'phone', 'minimum_order', 'delivery_conditions']));
        if ($user->isWholesaler() && $request->filled('active_trade_mode')) {
            $mode = TradeMode::from($request->string('active_trade_mode')->toString());
            abort_unless($organization->permitsMode($mode), 422);
            $user->update(['active_trade_mode' => $mode]);
        }
        app(AuditLogger::class)->log('organization.updated', $organization, $before, $organization->fresh()->only('name', 'city', 'phone', 'minimum_order', 'delivery_conditions'));

        return back()->with('success', 'Настройки организации сохранены.');
    }
}
