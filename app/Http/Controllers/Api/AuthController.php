<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Session-based (Sanctum SPA) login for window staff. */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $credentials['username'], 'password' => $credentials['password']], true)) {
            throw ValidationException::withMessages(['username' => 'Mali ang username o password.']);
        }

        $request->session()->regenerate();

        return $request->user()->load('counter.service')->toArray() + ['csrf_token' => csrf_token()];
    }

    public function me(Request $request)
    {
        return $request->user()->load('counter.service');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }
}
