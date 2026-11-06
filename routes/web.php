<?php

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\agency\AgencyController;
use App\Http\Controllers\ai\AiChatController;
use App\Http\Controllers\ai\AiChatLogController;
use App\Http\Controllers\api\PortalController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\company\CompanyController;
use App\Http\Controllers\documents\DocumentExplorerController;
use App\Http\Controllers\documents\DocumentShareController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireDay90Controller;
use App\Http\Controllers\ExportExcelExpire\LabourExpirePassportController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireVisaController;
use App\Http\Controllers\ExportExcelExpire\LabourExpireWorkpremitController;
use App\Http\Controllers\import\labour\ImportLabour;
use App\Http\Controllers\labour\LabourController;
use App\Http\Controllers\labour\LabourDocumentController;
use App\Http\Controllers\labour\labourMassUpdateController;
use App\Http\Controllers\member\AuthController;
use App\Http\Controllers\member\RoleController;
use App\Http\Controllers\member\ProfileController;
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

// Worker portal downloads: temporary signed URLs issued by the portal API (no login, valid 15 minutes).
Route::get('/portal-file/{labourModel}/{key}', [PortalController::class, 'file'])->middleware('signed')->name('portal.file');

// Shared document folders (no login; the token is the key). Rate-limited per IP.
Route::prefix('share')->middleware('throttle:120,1')->group(function () {
    Route::get('/{token}', [DocumentShareController::class, 'show'])->name('share.show');
    Route::get('/{token}/open', [DocumentShareController::class, 'open'])->name('share.open');
    Route::post('/{token}/unlock', [DocumentShareController::class, 'unlock'])->name('share.unlock')->middleware('throttle:10,1,unlock');
    Route::get('/{token}/file/{document}', [DocumentShareController::class, 'file'])->name('share.file');
    Route::post('/{token}/zip', [DocumentShareController::class, 'zip'])->name('share.zip');
    Route::get('/{token}/legacy/{labourModel}/{column}', [DocumentShareController::class, 'legacy'])->name('share.legacy');
});

Route::middleware('auth')->group(function () {

    // ---------------------------------------------------------------- Dashboard
    Route::get('/', [NotificationController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [AnalyticsController::class, 'index'])->name('analytics.index')->middleware('perm:analytics.view');
    Route::get('/home', fn () => redirect()->route('dashboard'))->name('home');
    Route::get('/totalnotify', [NotificationController::class, 'notify'])->name('dashboard.notify');

    // ---------------------------------------------------------------- My profile (name, password, picture)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.delete');

    // ---------------------------------------------------------------- Labour
    Route::middleware('perm:labour.view')->group(function () {
        Route::get('/labour', [LabourController::class, 'index'])->name('labour.index');
        Route::get('/labour/form-show/{labourModel}', [LabourController::class, 'show'])->name('labour.show');
        Route::get('/labour/{labourModel}/photo', [LabourController::class, 'photo'])->name('labour.photo');
    });
    Route::middleware('perm:labour.view,documents.view')->group(function () {
        Route::get('/labour/document/{document}/open', [LabourDocumentController::class, 'open'])->name('labour.document.open');
        Route::get('/labour/{labourModel}/legacy-file/{column}', [LabourDocumentController::class, 'openLegacy'])->name('labour.document.openLegacy');
    });
    Route::middleware('perm:labour.create')->group(function () {
        Route::get('/labour/form-add', [LabourController::class, 'create'])->name('labour.create');
        Route::post('/labour/store', [LabourController::class, 'store'])->name('labour.store');
    });
    Route::middleware('perm:labour.edit')->group(function () {
        Route::get('/labour/form-edit/{labourModel}', [LabourController::class, 'edit'])->name('labour.edit');
        Route::put('/labour/update/{labourModel}', [LabourController::class, 'update'])->name('labour.update');
        Route::post('/labour/massupdate', [labourMassUpdateController::class, 'massupdate'])->name('labour.massupdate');
    });
    Route::middleware('perm:labour.import')->group(function () {
        Route::get('/import/labour/form', [ImportLabour::class, 'importform'])->name('labour.importform');
        Route::post('/import/labour-excel', [ImportLabour::class, 'import'])->name('import-excel.labour');
    });
    Route::delete('/labour/delete/{labourModel}', [LabourController::class, 'destroy'])->name('labour.delete')->middleware('perm:labour.delete');

    // ---------------------------------------------------------------- Document explorer
    Route::middleware('perm:documents.view')->group(function () {
        Route::get('/documents', [DocumentExplorerController::class, 'index'])->name('documents.index');
    });
    Route::middleware('perm:documents.download')->group(function () {
        Route::get('/documents/zip/{labourModel}', [DocumentExplorerController::class, 'zip'])->name('documents.zip');
        Route::post('/documents/zip', [DocumentExplorerController::class, 'zipSelected'])->name('documents.zip.selected');
    });
    Route::post('/documents/upload', [DocumentExplorerController::class, 'upload'])->name('documents.upload')->middleware('perm:documents.upload');
    Route::patch('/documents/{document}', [DocumentExplorerController::class, 'rename'])->name('documents.rename')->middleware('perm:documents.rename');
    Route::middleware('perm:documents.share')->group(function () {
        Route::get('/documents/shares', [DocumentShareController::class, 'index'])->name('documents.shares');
        Route::post('/documents/share', [DocumentShareController::class, 'store'])->name('documents.share.store');
        Route::patch('/documents/share/{share}', [DocumentShareController::class, 'update'])->name('documents.share.update');
        Route::post('/documents/share/{share}/notify', [DocumentShareController::class, 'notify'])->name('documents.share.notify');
        Route::delete('/documents/share/{share}', [DocumentShareController::class, 'revoke'])->name('documents.share.revoke');
    });
    Route::middleware('perm:documents.delete')->group(function () {
        Route::delete('/labour/document/{document}', [LabourDocumentController::class, 'destroy'])->name('labour.document.delete');
        Route::delete('/labour/{labourModel}/legacy-file/{column}', [LabourDocumentController::class, 'destroyLegacy'])->name('labour.document.deleteLegacy');
    });

    // ---------------------------------------------------------------- Company
    Route::get('/company', [CompanyController::class, 'index'])->name('company.index')->middleware('perm:company.view');
    Route::middleware('perm:company.create')->group(function () {
        Route::get('/company/form-add', [CompanyController::class, 'create'])->name('company.create');
        Route::post('/company/store', [CompanyController::class, 'store'])->name('company.store');
    });
    Route::middleware('perm:company.edit')->group(function () {
        Route::get('/company/form-edit/{companyModel}', [CompanyController::class, 'edit'])->name('company.edit');
        Route::put('/company/update/{companyModel}', [CompanyController::class, 'update'])->name('company.update');
    });
    Route::delete('/company/delete/{companyModel}', [CompanyController::class, 'destroy'])->name('company.delete')->middleware('perm:company.delete');

    // Address cascade (used by the company form)
    Route::post('/province', [CompanyController::class, 'province'])->name('province');
    Route::post('/amphur', [CompanyController::class, 'amphur'])->name('amphur');
    Route::post('/district', [CompanyController::class, 'district'])->name('district');

    // ---------------------------------------------------------------- Agency
    Route::get('/agency', [AgencyController::class, 'index'])->name('agency.index')->middleware('perm:agency.view');
    Route::middleware('perm:agency.create')->group(function () {
        Route::get('/agency/form-add', [AgencyController::class, 'create'])->name('agency.create');
        Route::post('/agency/store', [AgencyController::class, 'store'])->name('agency.store');
    });
    Route::middleware('perm:agency.edit')->group(function () {
        Route::get('/agency/form-edit/{agencyModel}', [AgencyController::class, 'edit'])->name('agency.edit');
        Route::put('/agency/update/{agencyModel}', [AgencyController::class, 'update'])->name('agency.update');
    });
    Route::delete('/agency/delete/{agencyModel}', [AgencyController::class, 'destroy'])->name('agency.delete')->middleware('perm:agency.delete');

    // ---------------------------------------------------------------- AI assistant
    Route::middleware('perm:ai.chat')->group(function () {
        Route::get('/ai-chat', [AiChatController::class, 'index'])->name('ai.chat');
        Route::post('/ai-chat/message', [AiChatController::class, 'message'])->name('ai.message')->middleware('throttle:30,1');
        Route::get('/ai-chat/report/{file}', [AiChatController::class, 'report'])->name('ai.report');
        Route::get('/ai-chat/image/{file}', [AiChatController::class, 'image'])->name('ai.image');
        Route::get('/ai-chat/file/{file}', [AiChatController::class, 'file'])->name('ai.file');
        Route::post('/ai-chat/feedback/{log}', [AiChatController::class, 'feedback'])->name('ai.feedback')->middleware('throttle:60,1');
        Route::get('/ai-chat/plan', [AiChatController::class, 'plan'])->name('ai.plan')->middleware('throttle:30,1');
    });
    Route::get('/ai-chat/logs', [AiChatLogController::class, 'index'])->name('ai.logs')->middleware('perm:ai.logs');

    // ---------------------------------------------------------------- Reports & exports
    Route::middleware('perm:report.view')->group(function () {
        Route::get('/Report', [ReportLabourController::class, 'index'])->name('report.index');
        Route::post('/Report/custom/pdf', [labourCustomController::class, 'exportPDF'])->name('report.customPDF');
        Route::post('/Report/custom/date', [LabourCustomDateController::class, 'exportto'])->name('report.customdate');

        Route::get('/export/expire/visa', [LabourExpireVisaController::class, 'export'])->name('ExportExpire.Visa');
        Route::get('/export/expire/passport', [LabourExpirePassportController::class, 'export'])->name('ExportExpire.passport');
        Route::get('/export/expire/workpremit', [LabourExpireWorkpremitController::class, 'export'])->name('ExportExpire.workpremit');
        Route::get('/export/expire/day90', [LabourExpireDay90Controller::class, 'export'])->name('ExportExpire.day90');
    });

    // ---------------------------------------------------------------- Management
    Route::middleware('perm:admin.settings')->group(function () {
        Route::get('/settings', [settingsController::class, 'index'])->name('setting.index');
        Route::post('/settings/update', [settingsController::class, 'update'])->name('setting.update');
    });
    Route::middleware('perm:admin.users')->group(function () {
        Route::get('/member', [AuthController::class, 'index'])->name('member.index');
        Route::get('/member/ajax', [AuthController::class, 'member_ajax'])->name('member.indexAjax');
        Route::put('/member/update/{id}', [AuthController::class, 'update'])->name('member.update');
        Route::delete('/member/delete/{id}', [AuthController::class, 'destroy'])->name('member.delete');

        // Create member (uses the Laravel UI register controller)
        Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
        Route::post('/register', [RegisterController::class, 'register']);
    });
    Route::middleware('perm:admin.roles')->group(function () {
        Route::get('/roles/{key?}', [RoleController::class, 'index'])->name('roles.index')->where('key', '[A-Za-z][A-Za-z0-9_]*');
        Route::post('/roles/create', [RoleController::class, 'store'])->name('roles.store');
        Route::post('/roles/{key}/permissions', [RoleController::class, 'update'])->name('roles.update');
        Route::patch('/roles/{key}', [RoleController::class, 'rename'])->name('roles.rename');
        Route::post('/roles/{key}/reset', [RoleController::class, 'reset'])->name('roles.reset');
        Route::delete('/roles/{key}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
    Route::get('/action-logs', [ActionLogController::class, 'index'])->name('action-logs.index')->middleware('perm:admin.logs');
});
