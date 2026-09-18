<?php

namespace App\Http\Controllers;

use App\Models\SupplierInvitation;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupplierInvitationController extends Controller
{
    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $code = strtoupper(Str::random(16));
        $invitation = $request->user()->organization->supplierInvitations()->create([
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addDays(30),
        ]);
        $auditLogger->log('supplier_invitation.created', $invitation);

        return back()->with('invitation_code', $code)->with('success', 'Код создан. Сохраните его сейчас: повторно он не показывается.');
    }

    public function revoke(Request $request, SupplierInvitation $invitation, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($invitation->supplier_organization_id === $request->user()->organization_id, 404);
        abort_if($invitation->redeemed_at, 422);
        $invitation->update(['revoked_at' => now()]);
        $auditLogger->log('supplier_invitation.revoked', $invitation);

        return back()->with('success', 'Код отозван.');
    }
}
