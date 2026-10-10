<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::post('settings/profile/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:10,1')->name('profile.avatar.upload');
Route::get('settings/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
Route::delete('settings/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.delete');
Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
Route::get('settings/security', [SecurityController::class, 'edit'])->middleware(RequirePassword::class)->name('security.edit');
Route::put('settings/password', [SecurityController::class, 'update'])->middleware('throttle:6,1')->name('user-password.update');
