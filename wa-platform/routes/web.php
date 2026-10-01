<?php

use App\Http\Controllers\Api\CannedReplyController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NumberController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\Settings\CannedReplyAdminController;
use App\Http\Controllers\Settings\FlagRuleController;
use App\Http\Controllers\Settings\NumberAdminController;
use App\Http\Controllers\Settings\UserAdminController;
use App\Http\Controllers\Supervisor\AuditController;
use App\Http\Controllers\Supervisor\DashboardController;
use App\Http\Controllers\Supervisor\FlagReviewController;
use App\Http\Controllers\Supervisor\MonitorController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public webhook (no auth, CSRF-exempt) — Meta calls these.
// ---------------------------------------------------------------------------
Route::get('/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'receive']);

// ---------------------------------------------------------------------------
// One-time web installer (guarded inside the controller).
// ---------------------------------------------------------------------------
Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'run']);

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/', fn () => redirect(auth()->check() ? '/inbox' : '/login'));

// ---------------------------------------------------------------------------
// Authenticated app
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    // Agent inbox PWA shell.
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');

    // JSON API consumed by the PWA (same-origin, session cookie auth).
    Route::prefix('api')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/numbers', [NumberController::class, 'index']);
        Route::get('/canned-replies', [CannedReplyController::class, 'index']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/conversations/{conversation}/assign', [ConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/resolve', [ConversationController::class, 'resolve']);
    });

    // Supervisor + owner monitoring area.
    Route::middleware('role:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/agents', [DashboardController::class, 'agents'])->name('agents');
        Route::get('/conversations', [MonitorController::class, 'index'])->name('conversations');
        Route::get('/conversations/{conversation}', [MonitorController::class, 'show'])->name('conversation');
        Route::get('/flags', [FlagReviewController::class, 'index'])->name('flags');
        Route::post('/flags/{flag}/review', [FlagReviewController::class, 'review'])->name('flags.review');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit');
        Route::get('/export/messages', [MonitorController::class, 'export'])->name('export.messages');
    });

    // Owner-only settings.
    Route::middleware('role:owner')->prefix('settings')->name('settings.')->group(function () {
        Route::resource('numbers', NumberAdminController::class)->except(['show']);
        Route::resource('users', UserAdminController::class)->except(['show']);
        Route::resource('flag-rules', FlagRuleController::class)->except(['show'])->parameters(['flag-rules' => 'flagRule']);
        Route::resource('canned', CannedReplyAdminController::class)->except(['show'])->parameters(['canned' => 'cannedReply']);
    });
});
