@if(config('account-modes.enabled') && auth()->check())
<input type="hidden" name="_mode_version" value="{{ app(\App\Services\AccountModeContext::class)->version }}">
@endif
