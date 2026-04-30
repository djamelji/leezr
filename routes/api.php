<?php

use App\Modules\Infrastructure\Auth\Http\AuthController;
use App\Modules\Infrastructure\Auth\Http\PasswordResetController;
use App\Modules\Infrastructure\Webhooks\Http\WebhookController;
use App\Modules\Infrastructure\Public\Http\PublicAddonController;
use App\Modules\Infrastructure\Public\Http\HelpCenterController;
use App\Modules\Infrastructure\Public\Http\PublicFieldController;
use App\Modules\Infrastructure\Public\Http\PublicCouponController;
use App\Modules\Infrastructure\Public\Http\PublicPlanController;
use App\Modules\Infrastructure\Public\Http\PublicI18nController;
use App\Modules\Infrastructure\Public\Http\PublicMarketController;
use App\Modules\Infrastructure\Public\Http\PublicWorldController;
use App\Modules\Infrastructure\Public\Http\PublicThemeController;
use App\Modules\Infrastructure\System\Http\RuntimeErrorController;
use App\Modules\Platform\Audience\Http\AudienceController;
use Illuminate\Support\Facades\Route;

// Public (no auth) — rate limited
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/register/funnel-track', \App\Modules\Infrastructure\Auth\Http\FunnelTrackController::class)->middleware('throttle:30,1');
Route::post('/register/confirm-payment', \App\Modules\Infrastructure\Auth\Http\ConfirmRegistrationPaymentController::class)
    ->middleware(['auth:sanctum', 'throttle:10,1']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:15,1');
Route::post('/2fa/verify', [AuthController::class, 'verify2fa'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');

// ADR-330: Lightweight version endpoint for live version polling
Route::get('/public/version', fn () => response()->json([
    'version' => config('app.build_version', 'dev'),
]))->middleware('throttle:60,1');

// Public theme (primary color + typography for unauthenticated pages)
Route::get('/public/theme', PublicThemeController::class)->middleware('throttle:30,1');

// Public world settings (locale, currency, timezone for all pages)
Route::get('/public/world', PublicWorldController::class)->middleware('throttle:30,1');

// Public plans & pricing (ADR-100 — no auth)
Route::prefix('public')->middleware('throttle:30,1')->group(function () {
    Route::get('/plans', [PublicPlanController::class, 'index']);
    Route::get('/plans/preview', [PublicPlanController::class, 'preview']);
    Route::post('/plans/estimate-registration', [PublicPlanController::class, 'estimateRegistration']);
    Route::get('/fields', [PublicFieldController::class, 'companyFields']);
    Route::get('/addons', [PublicAddonController::class, 'index']);
    Route::post('/validate-coupon', PublicCouponController::class)->middleware('throttle:10,1');
});

// Help Center — unified API (ADR-356 — optional auth via session cookies)
Route::prefix('help-center')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [HelpCenterController::class, 'index']);
    Route::get('/search', [HelpCenterController::class, 'search']);
    Route::get('/topic/{slug}', [HelpCenterController::class, 'topic']);
    Route::get('/article/{topicSlug}/{articleSlug}', [HelpCenterController::class, 'article']);
    Route::post('/article/{id}/feedback', [HelpCenterController::class, 'feedback']);
});

// Public markets & i18n (ADR-104 — no auth)
Route::prefix('public')->middleware('throttle:30,1')->group(function () {
    Route::get('/markets', [PublicMarketController::class, 'index']);
    Route::get('/markets/{key}', [PublicMarketController::class, 'show']);
    Route::get('/i18n/{locale}/{namespace?}', [PublicI18nController::class, 'bundle']);
});

// Audience — public (throttled, no auth, module-gated)
Route::prefix('audience')->middleware(['module.active:platform.audience', 'throttle:10,1'])->group(function () {
    Route::post('/subscribe', [AudienceController::class, 'subscribe']);
    Route::post('/confirm', [AudienceController::class, 'confirm']);
    Route::post('/unsubscribe', [AudienceController::class, 'unsubscribe']);
    Route::get('/maintenance-page', [AudienceController::class, 'maintenancePage']);
});

// Webhooks — public (no auth, external services)
Route::post('/webhooks/billing', WebhookController::class)->middleware('throttle:60,1');

// Provider-specific webhooks with idempotency (ADR-124)
Route::post('/webhooks/payments/{providerKey}', \App\Modules\Infrastructure\Webhooks\Http\PaymentWebhookController::class)
    ->middleware('throttle:120,1');

// Runtime error reporting — public (no auth, frontend → backend)
Route::post('/runtime-error', RuntimeErrorController::class)->middleware('throttle:10,1');

// Authenticated (auth:sanctum)
Route::middleware(['auth:sanctum', 'session.governance'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-companies', [AuthController::class, 'myCompanies']);

    // Heartbeat (session keepalive — governance middleware handles TTL header)
    Route::post('/heartbeat', fn () => response()->noContent());
});
