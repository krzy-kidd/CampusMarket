<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::get('/categories', [ListingController::class, 'categories']);
Route::get('/listings', [ListingController::class, 'index']);
Route::get('/listings/{listing}', [ListingController::class, 'show'])->whereNumber('listing');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationNotification'])
            ->middleware('throttle:3,1');
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::get('/verification/status', [VerificationController::class, 'status']);
        Route::get('/verification/history', [VerificationController::class, 'history']);
        Route::get('/listings', [ListingController::class, 'mine']);
        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:5,1');
        Route::get('/listings/{listing}', [ListingController::class, 'mineShow'])->whereNumber('listing');
        Route::post('/listings', [ListingController::class, 'store'])->middleware('throttle:10,1');
        Route::patch('/listings/{listing}', [ListingController::class, 'update'])->whereNumber('listing');
        Route::delete('/listings/{listing}', [ListingController::class, 'archive'])->whereNumber('listing');
        Route::post('/listings/{listing}/relist', [ListingController::class, 'relist'])->whereNumber('listing');
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
            ->middleware('throttle:10,1');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('notification')
            ->middleware('throttle:30,1');
        Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/transactions', [TransactionController::class, 'createFromConversation'])
            ->whereNumber('conversation')
            ->middleware('throttle:10,1');
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'olderMessages'])
            ->whereNumber('conversation');
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->whereNumber('conversation')
            ->middleware('throttle:30,1');
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction');
        Route::post('/transactions/{transaction}/reviews', [TransactionController::class, 'submitReview'])
            ->whereNumber('transaction')
            ->middleware('throttle:10,1');
        Route::post('/transactions/{transaction}/meetup', [TransactionController::class, 'proposeMeetup'])
            ->whereNumber('transaction')
            ->middleware('throttle:20,1');
        Route::post('/transactions/{transaction}/complete', [TransactionController::class, 'confirmCompletion'])
            ->whereNumber('transaction')
            ->middleware('throttle:20,1');
        Route::post('/transactions/{transaction}/unlock-request', [TransactionController::class, 'requestUnlock'])
            ->whereNumber('transaction')
            ->middleware('throttle:10,1');
        Route::post('/transactions/{transaction}/unlock-response', [TransactionController::class, 'respondToUnlock'])
            ->whereNumber('transaction')
            ->middleware('throttle:10,1');
        Route::post('/transactions/{transaction}/dispute', [TransactionController::class, 'dispute'])
            ->whereNumber('transaction')
            ->middleware('throttle:5,1');
        Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])
            ->whereNumber('transaction')
            ->middleware('throttle:10,1');
        Route::post('/verification/cor', [VerificationController::class, 'submit'])
            ->middleware(['verified', 'throttle:5,1']);
        Route::get('/verification-attempts/{attempt}/file', [VerificationController::class, 'file'])
            ->whereNumber('attempt');

        Route::prefix('admin')
            ->middleware('verification.admin')
            ->group(function () {
                Route::get('/dashboard', [AdminDashboardController::class, 'overview']);
                Route::get('/verifications', [AdminVerificationController::class, 'index']);
                Route::post('/verifications/{verification}/attempts/{attempt}/review', [AdminVerificationController::class, 'review'])
                    ->whereNumber(['verification', 'attempt']);

                Route::middleware('super.admin')->group(function () {
                    Route::get('/academic-years', [AdminDashboardController::class, 'academicYears']);
                    Route::post('/academic-years', [AdminDashboardController::class, 'storeAcademicYear']);
                    Route::patch('/academic-years/{academicYear}', [AdminDashboardController::class, 'updateAcademicYear'])
                        ->whereNumber('academicYear');
                    Route::delete('/academic-years/{academicYear}', [AdminDashboardController::class, 'destroyAcademicYear'])
                        ->whereNumber('academicYear');

                    Route::get('/categories', [AdminDashboardController::class, 'categories']);
                    Route::post('/categories', [AdminDashboardController::class, 'storeCategory']);
                    Route::patch('/categories/{category}', [AdminDashboardController::class, 'updateCategory'])
                        ->whereNumber('category');
                    Route::delete('/categories/{category}', [AdminDashboardController::class, 'destroyCategory'])
                        ->whereNumber('category');

                    Route::get('/students', [AdminDashboardController::class, 'students']);
                    Route::patch('/students/{user}', [AdminDashboardController::class, 'updateStudent'])
                        ->whereNumber('user');

                    Route::get('/admins', [AdminDashboardController::class, 'admins']);
                    Route::post('/admins', [AdminDashboardController::class, 'storeAdmin']);
                    Route::patch('/admins/{admin}', [AdminDashboardController::class, 'updateAdmin'])
                        ->whereNumber('admin');

                    Route::get('/reports', [AdminDashboardController::class, 'reports']);
                    Route::patch('/reports/{report}', [AdminDashboardController::class, 'updateReport'])
                        ->whereNumber('report');

                    Route::get('/platform-settings', [AdminDashboardController::class, 'platformSettings']);
                    Route::patch('/platform-settings', [AdminDashboardController::class, 'updatePlatformSettings']);

                    Route::get('/listings', [AdminDashboardController::class, 'listings']);
                    Route::patch('/listings/{listing}/archive', [AdminDashboardController::class, 'archiveListing'])
                        ->whereNumber('listing');

                    Route::get('/audit-logs', [AdminDashboardController::class, 'auditLogs']);
                });
            });
    });
});
