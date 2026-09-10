<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\ActivationHistory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $r->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Слишком много попыток.']);
        } if (! Auth::attempt($r->only('email', 'password'), $r->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Неверные данные.']);
        } $r->session()->regenerate();
        RateLimiter::clear($key);

        return $r->user()->isAdmin() ? redirect()->route('two-factor.enroll') : redirect()->intended(route('dashboard'));
    }

    public function registerForm()
    {
        return view('auth.register');
    }

    public function register(Request $r)
    {
        $data = $r->validate(['pharmacy_name' => 'required|string|max:255', 'phone' => 'required|string|max:30|unique:users,phone', 'email' => 'required|email|unique:users', 'password' => 'required|confirmed|min:8']);
        $org = Organization::create(['name' => $data['pharmacy_name'], 'phone' => $data['phone'], 'type' => OrganizationType::Pharmacy, 'status' => 'pending']);
        $u = new User($data);
        $u->forceFill(['organization_id' => $org->id, 'role' => UserRole::Pharmacy, 'active_trade_mode' => TradeMode::Buyer]);
        $u->save();
        ActivationHistory::firstOrCreate(['phone' => $data['phone']], ['organization_id' => $org->id]);

        return redirect()->route('login')->with('success', 'Заявка принята. После проверки администратора будет включён демо-доступ.');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
