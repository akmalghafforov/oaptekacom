<?php

namespace App\Http\Controllers;

use App\Enums\AccountMode;
use App\Http\Requests\UpdateAccountModeRequest;
use App\Services\AccountModeContext;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class AccountModeController extends Controller
{
    public function update(UpdateAccountModeRequest $request, AccountModeContext $context, AuditLogger $auditLogger): RedirectResponse
    {
        $before = ['mode' => $context->mode->value, 'organization_id' => $context->organization?->id];
        $context->switchTo($request, AccountMode::from($request->validated('mode')));
        if ($before['mode'] !== $context->mode->value) {
            $auditLogger->log('account.mode_switched', $request->user(), $before, ['mode' => $context->mode->value, 'organization_id' => $context->organization?->id]);
        }

        return redirect()->route($request->user()->defaultLandingRouteName());
    }
}
