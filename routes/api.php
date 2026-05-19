<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeletionRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\LlmController;
use App\Http\Controllers\GedAssistantController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\ChatSearchController;

// ── Authentification publique ──────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ── Prévisualisation publique ──────────────────────────────────────────────
Route::get('/documents/{document}/preview', [DocumentController::class, 'preview']);

// ── Routes protégées ───────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Notifications — accessible à TOUS les utilisateurs connectés
    Route::get('/notifications',             [NotificationController::class, 'index']);
    Route::get('/notifications/unread',      [NotificationController::class, 'unread']);
    Route::post('/notifications/{id}/read',  [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all',   [NotificationController::class, 'markAll']);

    // Documents
    Route::apiResource('documents', DocumentController::class);
    Route::get('/documents/{document}/download',  [DocumentController::class, 'download']);
    Route::post('/documents/{document}/archive',  [DocumentController::class, 'archive']);
    Route::post('/documents/{document}/restore',  [DocumentController::class, 'restore']);
    Route::post('/documents/{document}/comments', [DocumentController::class, 'addComment']);

    // Dossiers
    Route::apiResource('folders', FolderController::class);
    Route::get('/folders/tree/all',  [FolderController::class, 'tree']);
    Route::get('/my-folders',        [FolderController::class, 'myAccessibleFolders']);

    // Tags
    Route::apiResource('tags', TagController::class);

    // Workflows
    Route::apiResource('workflows', WorkflowController::class);
    Route::post('/workflows/{workflow}/approve', [WorkflowController::class, 'approve']);
    Route::post('/workflows/{workflow}/reject',  [WorkflowController::class, 'reject']);
    Route::post('/workflows/{workflow}/cancel',  [WorkflowController::class, 'cancel']);
    Route::get('/pending-approvals',             [WorkflowController::class, 'pendingApprovals']);

    // Groupes d'utilisateurs
    Route::apiResource('user-groups', UserGroupController::class);
    Route::post('/user-groups/{userGroup}/folder-access',                 [UserGroupController::class, 'grantFolderAccess']);
    Route::put('/user-groups/{userGroup}/folder-access/{folder}/approve', [UserGroupController::class, 'approveFolderAccess']);
    Route::get('/pending-folder-access',                                  [UserGroupController::class, 'pendingAccess']);

    // Utilisateurs
    Route::apiResource('users', UserController::class);
    Route::put('/users/{user}/password',       [UserController::class, 'changePassword']);
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

    // Journal d'audit — admin seulement
    Route::middleware('role:admin')->group(function () {
        Route::get('/audit',        [AuditController::class, 'index']);
        Route::get('/audit/export', [AuditController::class, 'export']);
        Route::get('/audit/stats',  [AuditController::class, 'stats']);
    });
    // Demandes de suppression
    Route::get('/deletion-requests',                          [DeletionRequestController::class, 'index']);
    Route::get('/deletion-requests/pending',                  [DeletionRequestController::class, 'pending']);
    Route::post('/deletion-requests',                         [DeletionRequestController::class, 'store']);
    Route::post('/deletion-requests/{deletionRequest}/approve',[DeletionRequestController::class, 'approve']);
    Route::post('/deletion-requests/{deletionRequest}/reject', [DeletionRequestController::class, 'reject']);
    Route::get('/my-deletion-requests',                       [DeletionRequestController::class, 'myRequests']);

    // Profil utilisateur
    Route::get('/profile',                          [ProfileController::class, 'show']);
    Route::put('/profile',                          [ProfileController::class, 'update']);
    Route::post('/profile/avatar',                  [ProfileController::class, 'uploadAvatar']);
    Route::put('/profile/password',                 [ProfileController::class, 'changePassword']);
    Route::put('/profile/notifications',            [ProfileController::class, 'updateNotifications']);
    Route::delete('/profile/sessions/{tokenId}',    [ProfileController::class, 'revokeSession']);
    Route::delete('/profile/sessions',              [ProfileController::class, 'revokeAllSessions']);

    // Accès dossiers
    Route::post('/folders/{folder}/request-access',          [FolderController::class, 'requestAccess']);
    Route::get('/folder-access-requests',                    [FolderController::class, 'accessRequests']);
    Route::post('/folder-access-requests/{id}/approve',      [FolderController::class, 'approveAccessRequest']);
    Route::post('/folder-access-requests/{id}/reject',       [FolderController::class, 'rejectAccessRequest']);
    
    // Recherche globale
    Route::get('/search', [SearchController::class, 'search']);

        // OCR
    Route::get('/ocr/status',                  [OcrController::class, 'status']);
    Route::post('/documents/{document}/ocr',   [OcrController::class, 'process']);
    Route::get('/documents/{document}/ocr',    [OcrController::class, 'getText']);

    // LLM / IA
    Route::get('/ai/status',                        [LlmController::class, 'status']);
    Route::post('/documents/{document}/ai/analyze', [LlmController::class, 'analyze']);
    Route::post('/documents/{document}/ai/summary', [LlmController::class, 'summarize']);
    Route::post('/documents/{document}/ai/chat',    [LlmController::class, 'chat']);

    // Assistant GED
    Route::post('/assistant/chat',       [GedAssistantController::class, 'chat']);
    Route::get('/assistant/suggestions', [GedAssistantController::class, 'suggestions']);

    // Recherche semantique
    Route::post('/semantic-search',       [SemanticSearchController::class, 'search']);
    Route::get('/semantic-search/status', [SemanticSearchController::class, 'indexStatus']);

    Route::post('/chat-search',             [ChatSearchController::class, 'chat']);
    Route::get('/chat-search/suggestions',  [ChatSearchController::class, 'suggestions']);
});