<?php
use App\Http\Controllers\{AdminController,AuthController,CartController,CatalogController,DashboardController,OrderController,SubscriptionController};
use Illuminate\Support\Facades\Route;
Route::redirect('/','/catalog');
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'loginForm'])->name('login');Route::post('/login',[AuthController::class,'login'])->middleware('throttle:5,1');Route::get('/register',[AuthController::class,'registerForm'])->name('register');Route::post('/register',[AuthController::class,'register']);});
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function(){Route::get('/subscription',[SubscriptionController::class,'create'])->name('subscription.create');Route::post('/subscription',[SubscriptionController::class,'store'])->name('subscription.store');});
Route::middleware(['auth','active'])->group(function(){
Route::get('/dashboard',DashboardController::class)->name('dashboard');Route::get('/catalog',[CatalogController::class,'index'])->name('catalog');
Route::get('/cart',[CartController::class,'show'])->name('cart');Route::post('/cart/{offer}',[CartController::class,'add'])->name('cart.add');Route::patch('/cart/items/{item}',[CartController::class,'update'])->name('cart.update');Route::post('/cart/checkout',[CartController::class,'checkout'])->name('cart.checkout');
Route::get('/orders',[OrderController::class,'index'])->name('orders.index');Route::get('/orders/{order}',[OrderController::class,'show'])->name('orders.show');Route::patch('/orders/{order}/status',[OrderController::class,'status'])->name('orders.status');
Route::prefix('admin')->middleware('role:admin')->group(function(){Route::get('/',[AdminController::class,'index'])->name('admin.index');Route::post('/organizations/{organization}/approve',[AdminController::class,'approve'])->name('admin.approve');Route::post('/payments/{payment}',[AdminController::class,'payment'])->name('admin.payment');Route::get('/users',[AdminController::class,'users'])->name('admin.users');Route::post('/users/{user}/block',[AdminController::class,'toggleBlock'])->name('admin.block');});});
