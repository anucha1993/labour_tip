<?php

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\agency\AgencyController;
use App\Http\Controllers\ai\AiChatController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\company\CompanyController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireDay90Controller;
use App\Http\Controllers\ExportExcelExpire\LabourExpirePassportController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireVisaController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireWorkpremitController;
use App\Http\Controllers\import\labour\ImportLabour;
use App\Http\Controllers\labour\LabourController;
use App\Http\Controllers\labour\LabourDocumentController;
use App\Http\Controllers\labour\labourMassUpdateController;
use App\Http\Controllers\member\AuthController;
use App\Http\Controllers\notification\NotificationController;
use App\Http\Controllers\PDF\labourCustomController;
use App\Http\Controllers\PDF\LabourCustomDateController;
use App\Http\Controllers\report\ReportLabourController;
use App\Http\Controllers\settings\settingsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Roles:  Member      -> read-only (lists, detail pages, reports/exports)
|         Admin       -> Member + create/edit labour, company, agency, import, mass update, settings
|         MasterAdmin -> Admin  + delete, member management, action logs
*/

// Login / logout / password reset. Public self-registration is disabled;
// new accounts are created by a MasterAdmin from the member page.
Auth::routes(['register' => false, 'verify' => false]);

Route::middleware('auth')->group(function () {

    // ---------------------------------------------------------------- Dashboard
    Route::get('/', [NotificationController::class, 'index'])->name('dashboard');
    Route::get('/home', fn () => redirect()->route('dashboard'))->name('home');
    Route::get('/totalnotify', [NotificationController::class, 'notify'])->name('dashboard.notify');

    // ---------------------------------------------------------------- Labour (read)
    Route::get('/labour', [LabourController::class, 'index'])->name('labour.index');
    Route::get('/labour/form-show/{labourModel}', [LabourController::class, 'show'])->name('labour.show');
    Route::get('/labour/document/{document}/open', [LabourDocumentController::class, 'open'])->name('labour.document.open');
    Route::get('/labour/{labourModel}/legacy-file/{column}', [LabourDocumentController::class, 'openLegacy'])->name('labour.document.openLegacy');

    // ---------------------------------------------------------------- Company (read)
    Route::get('/company', [CompanyController::class, 'index'])->name('company.index');

    // Address cascade (used by the company form)
    Route::post('/province', [CompanyController::class, 'province'])->name('province');
    Route::post('/amphur', [CompanyController::class, 'amphur'])->name('amphur');
    Route::post('/district', [CompanyController::class, 'district'])->name('district');

    // ---------------------------------------------------------------- Agency (read)
    Route::get('/agency', [AgencyController::class, 'index'])->name('agency.index');

    // ---------------------------------------------------------------- AI assistant (read-only data access)
    Route::get('/ai-chat', [AiChatController::class, 'index'])->name('ai.chat');
    Route::post('/ai-chat/message', [AiChatController::class, 'message'])->name('ai.message')->middleware('throttle:30,1');
    Route::get('/ai-chat/report/{file}', [AiChatController::class, 'report'])->name('ai.report');

    // ---------------------------------------------------------------- Reports & exports
    Route::get('/Report', [ReportLabourController::class, 'index'])->name('report.index');
    Route::post('/Report/custom/pdf', [labourCustomController::class, 'exportPDF'])->name('report.customPDF');
    Route::post('/Report/custom/date', [LabourCustomDateController::class, 'exportto'])->name('report.customdate');

    Route::get('/export/expire/visa', [LabourExpireVisaController::class, 'export'])->name('ExportExpire.Visa');
    Route::get('/export/expire/passport', [LabourExpirePassportController::class, 'export'])->name('ExportExpire.passport');
    Route::get('/export/expire/workpremit', [LabourExpireWorkpremitController::class, 'export'])->name('ExportExpire.workpremit');
    Route::get('/export/expire/day90', [LabourExpireDay90Controller::class, 'export'])->name('ExportExpire.day90');

    // ================================================================ Admin + MasterAdmin
    Route::middleware('role:Admin,MasterAdmin')->group(function () {

        // Labour write
        Route::get('/labour/form-add', [LabourController::class, 'create'])->name('labour.create');
        Route::post('/labour/store', [LabourController::class, 'store'])->name('labour.store');
        Route::get('/labour/form-edit/{labourModel}', [LabourController::class, 'edit'])->name('labour.edit');
        Route::put('/labour/update/{labourModel}', [LabourController::class, 'update'])->name('labour.update');
        Route::post('/labour/massupdate', [labourMassUpdateController::class, 'massupdate'])->name('labour.massupdate');

        // Excel import
        Route::get('/import/labour/form', [ImportLabour::class, 'importform'])->name('labour.importform');
        Route::post('/import/labour-excel', [ImportLabour::class, 'import'])->name('import-excel.labour');

        // Company write
        Route::get('/company/form-add', [CompanyController::class, 'create'])->name('company.create');
        Route::post('/company/store', [CompanyController::class, 'store'])->name('company.store');
        Route::get('/company/form-edit/{companyModel}', [CompanyController::class, 'edit'])->name('company.edit');
        Route::put('/company/update/{companyModel}', [CompanyController::class, 'update'])->name('company.update');

        // Agency write
        Route::get('/agency/form-add', [AgencyController::class, 'create'])->name('agency.create');
        Route::post('/agency/store', [AgencyController::class, 'store'])->name('agency.store');
        Route::get('/agency/form-edit/{agencyModel}', [AgencyController::class, 'edit'])->name('agency.edit');
        Route::put('/agency/update/{agencyModel}', [AgencyController::class, 'update'])->name('agency.update');

        // Settings
        Route::get('/settings', [settingsController::class, 'index'])->name('setting.index');
        Route::post('/settings/update', [settingsController::class, 'update'])->name('setting.update');
    });

    // ================================================================ MasterAdmin only
    Route::middleware('role:MasterAdmin')->group(function () {

        // Deletes (DELETE verb + CSRF instead of plain GET links)
        Route::delete('/labour/delete/{labourModel}', [LabourController::class, 'destroy'])->name('labour.delete');
        Route::delete('/labour/document/{document}', [LabourDocumentController::class, 'destroy'])->name('labour.document.delete');
        Route::delete('/labour/{labourModel}/legacy-file/{column}', [LabourDocumentController::class, 'destroyLegacy'])->name('labour.document.deleteLegacy');
        Route::delete('/company/delete/{companyModel}', [CompanyController::class, 'destroy'])->name('company.delete');
        Route::delete('/agency/delete/{agencyModel}', [AgencyController::class, 'destroy'])->name('agency.delete');

        // Members
        Route::get('/member', [AuthController::class, 'index'])->name('member.index');
        Route::get('/member/ajax', [AuthController::class, 'member_ajax'])->name('member.indexAjax');
        Route::put('/member/update/{id}', [AuthController::class, 'update'])->name('member.update');
        Route::delete('/member/delete/{id}', [AuthController::class, 'destroy'])->name('member.delete');

        // Create member (uses the Laravel UI register controller)
        Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
        Route::post('/register', [RegisterController::class, 'register']);

        // Audit trail
        Route::get('/action-logs', [ActionLogController::class, 'index'])->name('action-logs.index');
    });
});
