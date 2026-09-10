<?php namespace App\Http\Controllers;
use App\Models\{ActivationHistory,Organization,User}; use Illuminate\Http\Request; use Illuminate\Support\Facades\{Auth,Hash,RateLimiter};
class AuthController extends Controller {
 public function loginForm(){return view('auth.login');}
 public function login(Request $r){$r->validate(['email'=>'required|email','password'=>'required']); $key='login:'.$r->ip(); if(RateLimiter::tooManyAttempts($key,5)) return back()->withErrors(['email'=>'Слишком много попыток.']); if(!Auth::attempt($r->only('email','password'),$r->boolean('remember'))){RateLimiter::hit($key,60); return back()->withErrors(['email'=>'Неверные данные.']);} $r->session()->regenerate(); RateLimiter::clear($key); return redirect()->intended(route('dashboard'));}
 public function registerForm(){return view('auth.register');}
 public function register(Request $r){$data=$r->validate(['pharmacy_name'=>'required|string|max:255','phone'=>'required|string|max:30|unique:users,phone','email'=>'required|email|unique:users','password'=>'required|confirmed|min:8']); $org=Organization::create(['name'=>$data['pharmacy_name'],'phone'=>$data['phone'],'type'=>'pharmacy','status'=>'pending']); $u=User::create(['name'=>$data['pharmacy_name'],'phone'=>$data['phone'],'email'=>$data['email'],'password'=>Hash::make($data['password']),'organization_id'=>$org->id,'role'=>'pharmacy']); ActivationHistory::firstOrCreate(['phone'=>$data['phone']],['organization_id'=>$org->id]); return redirect()->route('login')->with('success','Заявка принята. После проверки администратора будет включён демо-доступ.');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect()->route('login');}
}
