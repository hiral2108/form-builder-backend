<?php

use App\Http\Controllers\Front\ShopifyFrontController;
use App\Http\Controllers\Users\AuthController;
use App\Http\Controllers\Users\DashboardController;
use App\Http\Controllers\Users\ShopifyAuthLoginController;
use App\Http\Controllers\Users\ShopifyPlanController;
use App\Http\Controllers\Users\SubmissionController;
use App\Http\Controllers\Users\WidgetListController;
use App\Http\Controllers\Users\WidgetSettingController;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth.token'])->group(function () {
    Route::get('/get_user_data', [AuthController::class, 'get_user_data']);
    Route::post('/change_plan', [ShopifyPlanController::class, 'change_plan']);
    Route::post('/plan/annual', [ShopifyPlanController::class, 'annual']);
    Route::post('/plan/month', [ShopifyPlanController::class, 'month']);
    Route::post('/create_form', [WidgetListController::class, 'create_form']);
    Route::post('/get_form_list', [WidgetListController::class, 'get_form_list']);
    Route::post('/change_widget_status', [WidgetListController::class, 'change_widget_status']);
    Route::post('/rename_widget_title', [WidgetListController::class, 'rename_widget_title']);
    Route::post('/remove_widget', [WidgetListController::class, 'remove_widget']);
    Route::post('/save_widget_setting', [WidgetSettingController::class, 'save_widget_setting']);
    Route::get('/get_widget_setting', [WidgetSettingController::class, 'get_widget_setting']);
    Route::post('/upload_image', [WidgetSettingController::class, 'upload_image']);
    Route::post('/remove_image', [WidgetSettingController::class, 'remove_image']);
    Route::post('/clone_widget', [WidgetListController::class, 'clone_widget']);
    Route::get('/get_plan_list', [ShopifyPlanController::class, 'get_plan_list']);
    Route::post('/get_lead_list', [SubmissionController::class, 'index']);
    Route::post('/remove_lead', [SubmissionController::class, 'remove_lead']);
    Route::delete('/remove_all_lead', [SubmissionController::class, 'remove_all_lead']);
    Route::post('/export_lead', [SubmissionController::class, 'export_lead']);
    Route::match(['get', 'post'], '/get_dashboard_data', [DashboardController::class, 'get_dashboard_data']);
});

Route::get('/verify-token', function (Request $request) {
    $bearer = $request->header('Authorization', '');

    if (! $bearer) {
        return response()->json([
            'verify_token' => false,
            'message' => 'Authorization header missing',
        ], 401);
    }

    // Remove Bearer prefix
    $token = preg_replace('/^Bearer\s+/i', '', $bearer);

    try {

        if (! UserToken::where('user_token', $token)->exists()) {
            return response()->json([
                'verify_token' => false,
                'message' => 'Token revoked or not found',
            ], 401);
        }

        return response()->json([
            'verify_token' => true,
            'message' => 'Token found',
        ], 200);

    } catch (Throwable $e) {

        Log::error('Verify token failed', [
            'exception' => $e,
        ]);

        return response()->json([
            'verify_token' => false,
            'message' => 'Internal error while verifying token',
        ], 500);
    }

});

Route::post('/shopify/auth', [ShopifyAuthLoginController::class, 'login'])->name('login');
Route::post('/shopify/addUser', [ShopifyAuthLoginController::class, 'addUser']);

Route::post('get_forms_data', [ShopifyFrontController::class, 'get_forms_data'])->name('get_forms_data');
Route::post('submit_form', [ShopifyFrontController::class, 'submit_form'])->name('submit_form');
Route::post('visitors', [ShopifyFrontController::class, 'update_visitors'])->name('visitors');
Route::post('view_widget', [ShopifyFrontController::class, 'view_widget'])->name('view_widget');
Route::post('click_widget', [ShopifyFrontController::class, 'click_widget'])->name('click_widget');
