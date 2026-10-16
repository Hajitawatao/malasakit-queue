<?php

use Illuminate\Support\Facades\Route;

// Isang Vue SPA para sa tatlong screen: /kiosk, /display, /counter
Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting|sanctum).*$');
