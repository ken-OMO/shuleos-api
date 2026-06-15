<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\TeacherController;

Route::prefix('auth')->group(function () {

    // Public Route
    Route::post('/login', [AuthController::class, 'login']);

    // Protected Routes
    Route::middleware('jwt')->group(function () {

        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/refresh', [AuthController::class, 'refresh']);

    });

});

/*
|--------------------------------------------------------------------------
| School Routes
|--------------------------------------------------------------------------
*/
Route::prefix('schools')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [SchoolController::class, 'index']);

        Route::get('/{id}', [SchoolController::class, 'show']);

        Route::post('/', [SchoolController::class, 'store']);

        Route::put('/{id}', [SchoolController::class, 'update']);

        Route::delete('/{id}', [SchoolController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/
Route::prefix('users')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [UserController::class, 'index']);

        Route::get('/{id}', [UserController::class, 'show']);

        Route::post('/', [UserController::class, 'store']);

        Route::put('/{id}', [UserController::class, 'update']);

        Route::delete('/{id}', [UserController::class, 'destroy']);

        Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);

        Route::post('/{id}/assign-role', [UserController::class, 'assignRole']);

    });

/*
|--------------------------------------------------------------------------
| Teacher Routes
|--------------------------------------------------------------------------
*/
Route::prefix('teachers')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [TeacherController::class, 'index']);

        Route::get('/{id}', [TeacherController::class, 'show']);

        Route::post('/', [TeacherController::class, 'store']);

        Route::put('/{id}', [TeacherController::class, 'update']);

        Route::delete('/{id}', [TeacherController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Role Middleware Test Route
|--------------------------------------------------------------------------
*/
Route::get('/admin-test', function () {

    return response()->json([
        'success' => true,
        'message' => 'Platform Owner access granted'
    ]);

})->middleware(['jwt', 'role:Platform Owner']);

/*
|--------------------------------------------------------------------------
| Permission Middleware Test Route
|--------------------------------------------------------------------------
*/
Route::get('/permission-test', function () {

    return response()->json([
        'success' => true,
        'message' => 'Permission granted'
    ]);

})->middleware(['jwt', 'permission:manage_users']);
