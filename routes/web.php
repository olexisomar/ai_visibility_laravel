<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Authentication routes (public)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Account selection (requires auth)
Route::middleware('auth')->group(function () {
    Route::get('/select-account', [AuthController::class, 'showAccountSelect'])->name('account.select');
    Route::post('/select-account', [AuthController::class, 'selectAccount']);
    Route::post('/switch-account', [AuthController::class, 'switchAccount'])->name('account.switch');
});

// Dashboard and all other routes (requires auth + account)
Route::middleware(['auth', 'account.scope'])->group(function () {
    Route::get('/', function () {
        return view('dashboard');
    })->name('dashboard');
});

// Accounts Management (Super Admin Only)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::get('/accounts', [App\Http\Controllers\Admin\AccountController::class, 'index'])->name('admin.accounts');
    Route::get('/api/accounts', [App\Http\Controllers\Admin\AccountController::class, 'list'])->name('api.accounts.list');
    Route::post('/api/accounts', [App\Http\Controllers\Admin\AccountController::class, 'store'])->name('api.accounts.store');
    Route::put('/api/accounts/{id}', [App\Http\Controllers\Admin\AccountController::class, 'update'])->name('api.accounts.update');
    Route::delete('/api/accounts/{id}', [App\Http\Controllers\Admin\AccountController::class, 'destroy'])->name('api.accounts.destroy');
    Route::post('/api/accounts/assign-user', [App\Http\Controllers\Admin\AccountController::class, 'assignUser'])->name('api.accounts.assign-user');
    Route::post('/api/accounts/remove-user', [App\Http\Controllers\Admin\AccountController::class, 'removeUser'])->name('api.accounts.remove-user');
});

// User Management (Super Admin Only)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    // ... existing account routes ...
    
    // User routes
    Route::get('/users', [App\Http\Controllers\Admin\UserController::class, 'index'])->name('admin.users');
    Route::get('/api/users', [App\Http\Controllers\Admin\UserController::class, 'list'])->name('api.users.list');
    Route::post('/api/users', [App\Http\Controllers\Admin\UserController::class, 'store'])->name('api.users.store');
    Route::put('/api/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'update'])->name('api.users.update');
    Route::delete('/api/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('api.users.destroy');
    Route::post('/api/users/assign', [App\Http\Controllers\Admin\UserController::class, 'assignToAccount'])->name('api.users.assign');
    Route::post('/api/users/remove', [App\Http\Controllers\Admin\UserController::class, 'removeFromAccount'])->name('api.users.remove');
});

