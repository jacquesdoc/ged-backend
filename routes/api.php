<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

// ── Authentification (routes publiques) ───────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ── Routes protégées (token requis) ───────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Documents
    Route::apiResource('documents', DocumentController::class);
    Route::get('/documents/{document}/download',  [DocumentController::class, 'download']);
    Route::get('/documents/{document}/preview',   [DocumentController::class, 'preview']);
    Route::post('/documents/{document}/archive',  [DocumentController::class, 'archive']);
    Route::post('/documents/{document}/restore',  [DocumentController::class, 'restore']);
    Route::post('/documents/{document}/comments', [DocumentController::class, 'addComment']);

    // Dossiers
    Route::apiResource('folders', FolderController::class);
    Route::get('/folders/tree/all', [FolderController::class, 'tree']);

    // Tags
    Route::apiResource('tags', TagController::class);

    // Workflows
    Route::apiResource('workflows', WorkflowController::class);
    Route::post('/workflows/{workflow}/approve', [WorkflowController::class, 'approve']);
    Route::post('/workflows/{workflow}/reject',  [WorkflowController::class, 'reject']);
    Route::post('/workflows/{workflow}/cancel',  [WorkflowController::class, 'cancel']);
    Route::get('/pending-approvals',             [WorkflowController::class, 'pendingApprovals']);

    // Journal d'audit
    Route::get('/audit',        [AuditController::class, 'index']);
    Route::get('/audit/export', [AuditController::class, 'export']);
    Route::get('/audit/stats',  [AuditController::class, 'stats']);

    
  // Gestion des utilisateurs
    Route::apiResource('users', \App\Http\Controllers\UserController::class);
    Route::put('/users/{user}/password',      [\App\Http\Controllers\UserController::class, 'changePassword']);
    Route::post('/users/{user}/toggle-status',[\App\Http\Controllers\UserController::class, 'toggleStatus']);

});