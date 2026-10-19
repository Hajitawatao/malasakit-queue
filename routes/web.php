<?php

use Illuminate\Support\Facades\Route;

// Fresh CSRF token for the SPA (sent back in the X-CSRF-TOKEN header).
// Must stay above the catch-all route below.
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()])
    ->header('Cache-Control', 'no-store'));

// One Vue SPA for every screen: /kiosk, /display, /login, /counter
Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting|sanctum|csrf-token).*$');
