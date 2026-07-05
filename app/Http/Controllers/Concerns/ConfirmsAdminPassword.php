<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ConfigAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Requires the Super Admin to re-confirm their account password before a
 * sensitive configuration action (save / restore / import). Rate-limited and
 * audited; failures are logged and throttled per user+IP.
 */
trait ConfirmsAdminPassword
{
    /**
     * @return array{ok:bool, message:?string}
     */
    protected function confirmSecurity(Request $request): array
    {
        $user = $request->user();
        $key = 'config-confirm:'.$user->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return ['ok' => false, 'message' => "Too many attempts. Try again in {$seconds}s."];
        }

        $password = (string) $request->input('security_password');

        if ($password === '' || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 300);
            ConfigAuditLog::record('security_failed', [
                'success' => false,
                'message' => 'Incorrect password confirmation.',
                'section' => $request->route('section'),
            ]);

            return ['ok' => false, 'message' => 'Incorrect password. This action was not performed.'];
        }

        RateLimiter::clear($key);

        return ['ok' => true, 'message' => null];
    }
}
