<?php

namespace App\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Staff;
use App\Models\Admin;
use App\Models\Contractor;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    // ================= GOOGLE REDIRECT =================
    public function redirectToGoogle(Request $request)
    {
        return Socialite::driver('google')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    // ================= GOOGLE CALLBACK =================
    public function handleGoogleCallback()
    {
        try {
            // ── Lockout check ──────────────────────────────────────────────
            if ($this->isLockedOut()) {
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
            return match($user->role) {
                'admin'      => redirect('/admin/dashboard'),
                'staff'      => redirect('/staff/dashboard'),
                'contractor' => redirect('/contractor/dashboard'),
                default      => redirect()->route('login')->with('error', 'Invalid role.'),
            };

        } catch (\Exception $e) {
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

            return redirect()->route('login')
                ->with('error', "Too many failed attempts. You are locked out for {$humanTime}.")
                ->with('lockout_until', $until);
        }

        $nextTier     = collect(array_keys(self::LOCKOUT_TIERS))->first(fn($t) => $t > $totalFails);
        $untilLockout = $nextTier - $totalFails;

        return redirect()->route('login')
            ->with('error', "{$message} ({$untilLockout} attempt(s) before next lockout.)");
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
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}