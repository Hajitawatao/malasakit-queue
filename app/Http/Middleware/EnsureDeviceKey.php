<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Pinoprotektahan ang kiosk endpoint para hindi makapag-spam ng ticket
 * ang kahit sinong nasa hospital Wi-Fi. Ilagay ang key sa kiosk URL:
 *   http://queue.local/kiosk?key=XXXX  (sine-save ng frontend sa localStorage)
 */
class EnsureDeviceKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.queue_device_key');

        if ($expected && ! hash_equals($expected, (string) $request->header('X-Device-Key'))) {
            abort(401, 'Device not registered.');
        }

        return $next($request);
    }
}
