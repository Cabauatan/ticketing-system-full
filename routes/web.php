<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'message' => 'Ticketing API is ready. Connect the separate Vue frontend to /api.',
]))->name('home');

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
