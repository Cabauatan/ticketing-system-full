<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketChatController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketNotificationController;
use App\Http\Middleware\EnforceTicketPermissions;
use App\Services\TicketPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->get('csrf-token', fn (Request $request) => response()->json(['token' => $request->session()->token()]));

Route::middleware(['web', 'auth'])->get('me', fn (Request $request) => response()->json(['data' => [
    'user' => $request->user()->only(['id', 'name', 'email', 'role', 'email_verified_at']) + [
        'roles' => app(TicketPermissions::class)->rolesForUser($request->user()),
        'avatar_url' => $request->user()->avatarUrl(),
    ],
    'email_verified' => $request->user()->hasVerifiedEmail(),
]]))->name('api.me');

// First-party JSON API: share Fortify sessions and Laravel's CSRF protection.
Route::middleware(['web', 'auth', 'verified', EnforceTicketPermissions::class, 'throttle:120,1,chat'])->name('api.')->group(function () {
    Route::get('tickets/{ticket}/chat', [TicketChatController::class, 'index'])->whereNumber('ticket')->name('tickets.chat.index');
    Route::post('tickets/{ticket}/chat/presence', [TicketChatController::class, 'presence'])->whereNumber('ticket')->name('tickets.chat.presence');
});

Route::middleware(['web', 'auth', 'verified', EnforceTicketPermissions::class, 'throttle:60,1'])->name('api.')->group(function () {
    require __DIR__.'/settings.php';
    require __DIR__.'/inventory.php';
    Route::get('permissions', [PermissionController::class, 'mine'])->name('permissions');
    Route::get('admin/permissions', [PermissionController::class, 'index'])->name('admin.permissions.index');
    Route::put('admin/permissions/{role}', [PermissionController::class, 'update'])->middleware('throttle:20,1')->name('admin.permissions.update');
    Route::get('user', fn (Request $request) => response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'role']) + [
        'roles' => app(TicketPermissions::class)->rolesForUser($request->user()),
        'avatar_url' => $request->user()->avatarUrl(),
    ]]))->name('user');
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
    Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
    Route::post('tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->whereNumber('ticket')->middleware('throttle:20,1')->name('tickets.reopen');
    Route::post('tickets/{ticket}/comments', [TicketController::class, 'comment'])->whereNumber('ticket')->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->whereNumber('ticket')->middleware('throttle:20,1')->name('tickets.attachments.store');
    Route::get('tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'download'])->whereNumber(['ticket', 'attachment'])->name('tickets.attachments.download');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export.xlsx', [ReportController::class, 'export'])->middleware('throttle:30,1')->name('reports.export');
    Route::get('reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
    Route::get('reports/inventory.xlsx', [ReportController::class, 'inventoryExport'])->middleware('throttle:30,1')->name('reports.inventory.export');
    Route::get('reports/tickets.xlsx', [DashboardController::class, 'export'])->middleware('throttle:30,1')->name('reports.tickets');
    Route::get('notifications', [TicketNotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{notification}/read', [TicketNotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
    Route::get('admin', [AdminController::class, 'index'])->name('admin.index');
    Route::patch('admin/users/{user}/role', [AdminController::class, 'role'])->whereNumber('user')->middleware('throttle:30,1')->name('admin.users.role');
    Route::post('admin/categories', [AdminController::class, 'category'])->middleware('throttle:30,1')->name('admin.categories.save');
});
