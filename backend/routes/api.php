<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DamageReportController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\InspectionCriteriaController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LaboratoryController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (Request $request) {
    return response()->json([
        'status' => 'OK',
        'message' => 'Server is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('legacy.login.gate');

Route::middleware('verify.jwt')->group(function () {
    Route::get('/auth/profile', [AuthController::class, 'getProfile']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    // Users (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'getAllUsers']);
        Route::post('/users', [UserController::class, 'createUser']);
        Route::put('/users/{id}', [UserController::class, 'updateUser']);
        Route::delete('/users/{id}', [UserController::class, 'deleteUser']);
    });

    // Laboratories
    Route::get('/labs', [LaboratoryController::class, 'getAllLaboratories']);
    Route::post('/labs', [LaboratoryController::class, 'createLaboratory'])->middleware('role:admin', 'legacy.trim:nama_lab,lokasi');
    Route::put('/labs/{id}', [LaboratoryController::class, 'updateLaboratory'])->middleware('role:admin', 'legacy.trim:nama_lab,lokasi');
    Route::delete('/labs/{id}', [LaboratoryController::class, 'deleteLaboratory'])->middleware('role:admin');

    // Items
    Route::get('/items', [ItemController::class, 'getAllItems']);
    Route::get('/items/lab/{laboratoryId}', [ItemController::class, 'getItemsByLab']);
    Route::get('/items/my', [ItemController::class, 'getMyItems']);
    Route::get('/items/calibration-alerts', [ItemController::class, 'getCalibrationAlerts'])->middleware('role:admin');
    Route::get('/items/{id}', [ItemController::class, 'getItemById']);
    Route::middleware('role:admin,kalab,plp,teknisi')->group(function () {
        Route::post('/items', [ItemController::class, 'createItem']);
        Route::put('/items/{id}', [ItemController::class, 'updateItem']);
        Route::delete('/items/{id}', [ItemController::class, 'deleteItem']);
    });

    // Schedules
    Route::get('/schedules', [ScheduleController::class, 'getAllSchedules']);
    Route::get('/schedules/lab/{laboratoryId}', [ScheduleController::class, 'getSchedulesByLab']);
    Route::post('/schedules', [ScheduleController::class, 'createSchedule'])->middleware('role:admin', 'legacy.trim:keterangan');
    Route::put('/schedules/{id}', [ScheduleController::class, 'updateSchedule'])->middleware('role:admin', 'legacy.trim:keterangan');
    Route::delete('/schedules/{id}', [ScheduleController::class, 'deleteSchedule'])->middleware('role:admin');

    // Criteria
    Route::middleware('role:admin,kalab,plp,teknisi')->group(function () {
        Route::post('/criteria/category/create', [InspectionCriteriaController::class, 'createInspectionCategory'])->middleware('legacy.trim:nama_kategori,deskripsi');
        Route::post('/criteria/subitem/create', [InspectionCriteriaController::class, 'createInspectionSubitem'])->middleware('legacy.trim:nama_subitem,deskripsi');
        Route::post('/criteria/category-with-subitems/create', [InspectionCriteriaController::class, 'createInspectionCategoryWithSubitems'])->middleware('legacy.trim:nama_kategori,deskripsi,nama_subitem');
        Route::put('/criteria/category/{id}/update', [InspectionCriteriaController::class, 'updateCategory'])->middleware('legacy.trim:nama_kategori,deskripsi');
        Route::put('/criteria/subitem/{id}/update', [InspectionCriteriaController::class, 'updateSubitem'])->middleware('legacy.trim:nama_subitem,deskripsi');
        Route::put('/criteria/category-with-subitems/{id}/update', [InspectionCriteriaController::class, 'updateCategoryWithSubitems'])->middleware('legacy.trim:nama_kategori,deskripsi,nama_subitem');
        Route::get('/criteria/my', [InspectionCriteriaController::class, 'getMyCriteriaWithSubitems']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/criteria/category/pending', [InspectionCriteriaController::class, 'getPendingCategories']);
        Route::get('/criteria/subitem/pending', [InspectionCriteriaController::class, 'getPendingSubitems']);
        Route::put('/criteria/category/{id}/approve', [InspectionCriteriaController::class, 'approveCategory']);
        Route::put('/criteria/category/{id}/reject', [InspectionCriteriaController::class, 'rejectCategory']);
        Route::put('/criteria/subitem/{id}/approve', [InspectionCriteriaController::class, 'approveSubitem']);
        Route::put('/criteria/subitem/{id}/reject', [InspectionCriteriaController::class, 'rejectSubitem']);
        Route::put('/criteria/categories/bulk-approve', [InspectionCriteriaController::class, 'bulkApproveCategories']);
        Route::put('/criteria/categories/bulk-reject', [InspectionCriteriaController::class, 'bulkRejectCategories']);
        Route::delete('/criteria/category/{id}', [InspectionCriteriaController::class, 'deleteCategory']);
        Route::delete('/criteria/subitem/{id}', [InspectionCriteriaController::class, 'deleteSubitem']);
    });
    Route::get('/criteria/item/{itemId}', [InspectionCriteriaController::class, 'getCategoriesByItemId']);
    Route::get('/criteria/approved', [InspectionCriteriaController::class, 'getApprovedCategoriesWithSubitems']);

    // Inspections
    Route::middleware('role:admin,kalab,plp,teknisi')->group(function () {
        Route::post('/inspections/create', [InspectionController::class, 'createInspectionWithChecklist']);
        Route::put('/inspections/{id}/results', [InspectionController::class, 'updateInspectionResults']);
        Route::put('/inspections/result/{resultId}', [InspectionController::class, 'updateInspectionResult']);
        Route::put('/inspections/result/{resultId}/approve', [InspectionController::class, 'approveResultItem'])->middleware('role:admin');
        Route::put('/inspections/result/{resultId}/reject', [InspectionController::class, 'rejectResultItem'])->middleware('role:admin');
        Route::put('/inspections/results/bulk-approve', [InspectionController::class, 'bulkApproveResults'])->middleware('role:admin');
        Route::put('/inspections/results/bulk-reject', [InspectionController::class, 'bulkRejectResults'])->middleware('role:admin');
        Route::get('/inspections/export/{id}', [ExportController::class, 'exportInspection']);
        Route::get('/inspections/export-lab', [ExportController::class, 'exportLabItems']);
        Route::get('/inspections/export-all', [ExportController::class, 'exportAllCompleted']);
    });
    Route::get('/inspections/detail/{id}', [InspectionController::class, 'getInspectionDetail']);
    Route::get('/inspections/month/{bulan_ke}', [InspectionController::class, 'getInspectionsByMonth']);
    Route::get('/inspections/results/status', [InspectionController::class, 'getResultsByStatus'])->middleware('role:admin');
    Route::put('/inspections/{id}/approve-month/{bulan_ke}', [InspectionController::class, 'approveMonthlyResults'])->middleware('role:admin');
    Route::put('/inspections/{id}/reject-month/{bulan_ke}', [InspectionController::class, 'rejectMonthlyResults'])->middleware('role:admin', 'legacy.trim:alasan_penolakan');
    Route::get('/inspections/lab/{laboratoryId}/semesters', [InspectionController::class, 'getLabSemesters']);
    Route::get('/inspections/pending-reviews', [InspectionController::class, 'getPendingReviews'])->middleware('role:admin');
    Route::get('/inspections/by-items', [InspectionController::class, 'checkInspectionsByItemIds']);
    Route::get('/inspections/by-item/{item_id}', [InspectionController::class, 'checkInspectionByItemId']);
    Route::get('/inspections/my-pending', [InspectionController::class, 'getMyPendingInspections']);
    Route::delete('/inspections/{id}', [InspectionController::class, 'deleteInspection'])->middleware('role:admin');
    Route::get('/inspections/check-lab-status', [ExportController::class, 'checkLabInspectionsStatus']);

    // Damage reports
    Route::post('/damage-reports', [DamageReportController::class, 'saveDamageReport']);
    Route::get('/damage-reports/result/{inspectionResultId}', [DamageReportController::class, 'getDamageReport']);
    Route::delete('/damage-reports/result/{inspectionResultId}', [DamageReportController::class, 'deleteDamageReport']);

    // Exports
    Route::get('/export/laporan-peralatan-rusak', [ExportController::class, 'exportDamagedEquipmentReport'])->middleware('role:admin,kalab,plp,teknisi');
    Route::get('/export/repair-equipment', [ExportController::class, 'exportRepairEquipmentReport'])->middleware('role:admin,kalab,plp,teknisi');
    Route::get('/export/report-status', [ExportController::class, 'getExportReportStatus']);
});

// Upload endpoints tanpa auth, sama seperti project lama.
Route::post('/upload/lab', [UploadController::class, 'uploadLabReport']);
Route::post('/upload/lab/drive', [UploadController::class, 'uploadLabReportToDrive']);
