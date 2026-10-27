<?php

use App\Http\Controllers\api\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Consumed by the worker portal (labour.importer168.com). All routes require the
| X-Portal-Key header (PORTAL_API_KEY in .env on both servers).
*/

Route::prefix('portal')->middleware('portal.key')->group(function () {
    Route::post('/login', [PortalController::class, 'login'])->middleware('throttle:portal-login')->name('api.portal.login');
    Route::get('/labours/{labourModel}', [PortalController::class, 'show'])->name('api.portal.show');
    Route::get('/labours/{labourModel}/documents/{key}/link', [PortalController::class, 'documentLink'])->name('api.portal.documentLink');
});
