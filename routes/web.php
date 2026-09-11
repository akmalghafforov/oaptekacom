<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TwoFactorController;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/catalog');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/login/phone', [AuthController::class, 'sendLoginOtp'])->name('login.otp.send');
    Route::get('/login/phone/verify', [AuthController::class, 'loginOtpForm'])->name('login.otp.form');
    Route::post('/login/phone/verify', [AuthController::class, 'verifyLoginOtp'])->name('login.otp.verify');
    Route::post('/login/phone/resend', fn (Request $request, AuthController $controller, PhoneOtpService $otpService) => $controller->resend($request, $otpService, 'login'))->name('login.otp.resend');
    Route::get('/register', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/register/verify', [AuthController::class, 'registerOtpForm'])->name('register.otp.form');
    Route::post('/register/verify', [AuthController::class, 'verifyRegistrationOtp'])->name('register.otp.verify');
    Route::post('/register/resend', fn (Request $request, AuthController $controller, PhoneOtpService $otpService) => $controller->resend($request, $otpService, 'registration'))->name('register.otp.resend');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'create'])->name('subscription.create');
    Route::post('/subscription', [SubscriptionController::class, 'store'])->name('subscription.store');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/phone', [ProfileController::class, 'sendPhoneChange'])->name('profile.phone.send');
    Route::get('/profile/phone/verify', [ProfileController::class, 'phoneChangeForm'])->name('profile.phone.verify');
    Route::post('/profile/phone/verify', [ProfileController::class, 'confirmPhoneChange'])->name('profile.phone.confirm');
    Route::patch('/profile/organization', [ProfileController::class, 'updateOrganization'])->name('profile.organization.update');
    Route::get('/two-factor', [TwoFactorController::class, 'enroll'])->name('two-factor.enroll');
    Route::post('/two-factor', [TwoFactorController::class, 'confirm'])->middleware('throttle:5,1')->name('two-factor.confirm');
    Route::post('/two-factor/recovery', [TwoFactorController::class, 'recovery'])->middleware('throttle:5,1')->name('two-factor.recovery');
});
Route::middleware(['auth', 'active', 'two-factor-confirmed'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/catalog', [CatalogController::class, 'index'])->middleware('module:catalog')->name('catalog');
    Route::middleware('module:orders')->group(function () {
        Route::get('/cart', [CartController::class, 'show'])->name('cart');
        Route::post('/cart/{offer}', [CartController::class, 'add'])->name('cart.add');
        Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
        Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('/orders/{order}/status', [OrderController::class, 'status'])->name('orders.status');
    });
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.index');
        Route::post('/organizations/{organization}/approve', [AdminController::class, 'approve'])->name('admin.approve');
        Route::post('/payments/{payment}', [AdminController::class, 'payment'])->name('admin.payment');
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::post('/users/{user}/block', [AdminController::class, 'toggleBlock'])->name('admin.block');
        Route::post('/users/{user}/phone', [AdminController::class, 'remediatePharmacyPhone'])->name('admin.pharmacy.phone.remediate');
        Route::get('/modules', [AdminController::class, 'modules'])->name('admin.modules');
        Route::patch('/modules/{module}', [AdminController::class, 'updateModule'])->name('admin.modules.update');
        Route::post('/wholesalers', [AdminController::class, 'provisionWholesaler'])->name('admin.wholesalers.store');
    });
});
