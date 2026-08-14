<?php

namespace App\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Staff;
use App\Models\Admin;
use App\Models\Contractor;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    // True when the Google flow runs inside a popup opened from the login page.
    private bool $popup = false;

    // ================= GOOGLE REDIRECT =================
    public function redirectToGoogle(Request $request)
    {
        // Remember that this flow was started from a popup so the callback
        // can respond with a close-popup page instead of a full-page redirect.
        if ($request->query('popup') === '1') {
            session()->flash('google_login_popup', true);
        }

        return Socialite::driver('google')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    // ================= GOOGLE CALLBACK =================
    public function handleGoogleCallback()
    {
        try {
            // True when this login was opened from a popup window.
            $this->popup = request()->query('popup') === '1'
                || session()->pull('google_login_popup') === true;

            // ── Lockout check ──────────────────────────────────────────────
            if ($this->isLockedOut()) {
                if ($this->popup) {
                    return $this->popupResult(false, null, 'You are currently locked out.');
                }
                return redirect()->route('login')
                    ->with('error', 'You are currently locked out.')
                    ->with('lockout_until', session('login_lock_until'));
            }

            $googleUser = Socialite::driver('google')->stateless()->user();
            $email      = $googleUser->getEmail();

            // ── User check ─────────────────────────────────────────────────
            $user = User::where('email', $email)->first();

            if (!$user) {
                return $this->failedAttempt('Email not registered.');
            }

            // ── Disabled account check ───────────────────────────────────
            if (!$user->is_active) {
                return $this->failedAttempt('Your account has been disabled. Contact the administrator.');
            }

            // ── Device check (admin/staff desktop-only) ─────────────────────
            $userAgent = request()->header('User-Agent');
            $isMobile  = preg_match('/Android|iPhone|iPad|iPod/i', $userAgent);

            if (in_array($user->role, ['admin', 'staff']) && $isMobile) {
                return $this->failedAttempt('Admin/Staff login is only allowed on desktop.');
            }

            // ── Domain check (admin/staff must use CSPC email) ──────────────
            $domain = substr(strrchr($email, "@"), 1);

            if (in_array($user->role, ['admin', 'staff']) && $domain !== 'my.cspc.edu.ph') {
                return $this->failedAttempt('Only CSPC email allowed.');
            }

            // ── All checks passed — clear fail counter ─────────────────────
            session()->forget(['login_fail_count', 'login_lock_until']);

            // ── Save Google data ───────────────────────────────────────────
            $fullName  = $googleUser->getName();
            $nameParts = explode(' ', $fullName);
            $firstName = $nameParts[0] ?? null;
            $lastName  = count($nameParts) > 1 ? end($nameParts) : null;

            $user->update([
                'google_id'     => $googleUser->getId(),
                'name'          => $fullName,
                'profile_photo' => $googleUser->getAvatar(),
            ]);

            // ── Login ──────────────────────────────────────────────────────
            Auth::login($user);

            // ── Sync role tables ───────────────────────────────────────────
            if ($user->role === 'staff') {
                Staff::updateOrCreate(
                    ['user_id' => $user->id],
                    ['staff_id' => 'STAFF-' . rand(1000, 9999), 'first_name' => $firstName, 'last_name' => $lastName]
                );
            }
            if ($user->role === 'admin') {
                Admin::updateOrCreate(
                    ['user_id' => $user->id],
                    ['name' => $fullName, 'full_name' => $fullName]
                );
            }
            if ($user->role === 'contractor') {
                Contractor::updateOrCreate(
                    ['user_id' => $user->id],
                    ['name' => $fullName, 'contractor_name' => $fullName]
                );
            }

            // ── Redirect ───────────────────────────────────────────────────
            $redirect = match($user->role) {
                'admin'      => url('/admin/dashboard'),
                'staff'      => url('/staff/dashboard'),
                'contractor' => url('/contractor/dashboard'),
                default      => url('/login'),
            };

            if ($this->popup) {
                return $this->popupResult(true, $redirect, null);
            }

            return redirect($redirect);

        } catch (\Exception $e) {
            if ($this->popup) {
                return $this->popupResult(false, null, 'Google login failed.');
            }
            return redirect()->route('login')->with('error', 'Google login failed.');
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private const LOCKOUT_TIERS = [
        3 => 300,      // 3rd fail  → 5 minutes
        5 => 600,      // 5th fail  → 10 minutes
        7 => 86400,    // 7th fail  → 24 hours
    ];

    private function failedAttempt(string $message)
    {
        $totalFails = session('login_total_fails', 0) + 1;
        session(['login_total_fails' => $totalFails]);

        if (isset(self::LOCKOUT_TIERS[$totalFails])) {
            $seconds = self::LOCKOUT_TIERS[$totalFails];
            $until   = now()->addSeconds($seconds)->timestamp;
            session(['login_lock_until' => $until]);

            $humanTime = match($seconds) {
                300   => '5 minutes',
                600   => '10 minutes',
                86400 => '24 hours',
            };

            $flashMessage = "Too many failed attempts. You are locked out for {$humanTime}.";

            if ($this->popup) {
                return $this->popupResult(false, null, $flashMessage);
            }
            return redirect()->route('login')
                ->with('error', $flashMessage)
                ->with('lockout_until', $until);
        }

        $nextTier     = collect(array_keys(self::LOCKOUT_TIERS))->first(fn($t) => $t > $totalFails);
        $untilLockout = $nextTier - $totalFails;

        $flashMessage = "{$message} ({$untilLockout} attempt(s) before next lockout.)";

        if ($this->popup) {
            return $this->popupResult(false, null, $flashMessage);
        }
        return redirect()->route('login')->with('error', $flashMessage);
    }

    private function isLockedOut(): bool
    {
        $until = session('login_lock_until');
        return $until && now()->timestamp < $until;
    }

    private function lockoutSecondsRemaining(): int
    {
        return max(0, session('login_lock_until') - now()->timestamp);
    }

    // ================= LOGOUT =================
    public function logout(Request $request)
    {
        if (Auth::check()) {
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'Logout',
                'description' => Auth::user()->name . ' logged out',
                'ip_address' => $request->ip(),
                'visibility' => 'all',
            ]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ================= POPUP RESULT (loaded inside the auth popup) =================
    // Tells the opener (login page) whether sign-in succeeded, hands it the
    // dashboard URL when successful, then closes the popup window.
    private function popupResult(bool $success, ?string $redirect, ?string $error): \Illuminate\Http\Response
    {
        $payload = json_encode([
            'type'     => 'google-auth',
            'success'  => $success,
            'redirect' => $redirect,
            'error'    => $error,
        ]);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="robots" content="noindex">
            <title>Sign-in complete</title>
        </head>
        <body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F8FAFF;font-family:'Segoe UI',sans-serif;color:#172033">
            <div style="text-align:center;padding:0 24px">
                <p style="font-size:15px;font-weight:600;margin:0 0 6px">{$this->popupStatusLabel($success)}</p>
                <p style="font-size:12px;color:#64748B;margin:0">You can close this window.</p>
            </div>
            <script>
                (function () {
                    var origin = window.location.origin;
                    try {
                        if (window.opener) {
                            window.opener.postMessage({$payload}, origin);
                        }
                    } catch (e) {
                        /* ignore cross-origin / closed-opener errors */
                    }
                    window.close();
                })();
            </script>
        </body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html');
    }

    private function popupStatusLabel(bool $success): string
    {
        return $success ? 'Sign-in successful.' : 'Sign-in failed.';
    }
}