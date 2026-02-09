<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\RequestsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TelegramBotController;
use Illuminate\Support\Facades\Route;

// مسیرهای احراز هویت
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// مسیرهای احراز هویت شده
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pending-approval', [MembersController::class, 'pendingApproval'])->name('members.pending-approval');
    Route::get('/members', [MembersController::class, 'index'])->name('members.index');
    Route::post('/members', [MembersController::class, 'store'])->name('members.store');
    Route::get('/members/{id}/documents', [MembersController::class, 'getDocuments'])->name('members.documents');
    Route::post('/members/{id}/approve', [MembersController::class, 'approve'])->name('members.approve');
    Route::post('/members/{id}/reject', [MembersController::class, 'reject'])->name('members.reject');
    Route::get('/requests', [RequestsController::class, 'index'])->name('requests.index');

    // تنظیمات سیستم
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// مسیر ربات تلگرام
Route::post('/telegram/webhook', [TelegramBotController::class, 'handle'])->name('telegram.webhook');
Route::get('/telegram/poll', [TelegramBotController::class, 'handle'])->name('telegram.poll');
