<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupabaseAuthController extends Controller
{
    /**
     * Redirect the user to Supabase, which then sends them to Google.
     */
    public function redirect(Request $request)
    {
        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        $redirectUri = config('services.supabase.redirect');

        if (!$supabaseUrl || !$redirectUri) {
            abort(500, 'Supabase authentication is not configured.');
        }

        /*
         * Remember when this flow was started from the login page's popup
         * (?popup=1, see login.blade.php loginWithGoogle()). The session is
         * shared between the popup and its opener (same origin, same cookie),
         * so the callback — which also runs inside the popup — can read this
         * back and answer with the popup handoff page instead of a plain
         * redirect (a plain redirect would load the dashboard INSIDE the
         * 500px popup and strand the login tab behind it).
         */
        if ($request->query('popup') === '1') {
            $request->session()->put('supabase_login_popup', true);
        } else {
            $request->session()->forget('supabase_login_popup');
        }

        /*
         * PKCE protects the OAuth authorization-code exchange.
         * The verifier stays in the Laravel session.
         */
        $codeVerifier = Str::random(96);

        $codeChallenge = rtrim(
            strtr(
                base64_encode(hash('sha256', $codeVerifier, true)),
                '+/',
                '-_'
            ),
            '='
        );

        $request->session()->put(
            'supabase_code_verifier',
            $codeVerifier
        );

        $query = http_build_query([
            'provider' => 'google',
            'redirect_to' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 's256',
        ]);

        return redirect(
            $supabaseUrl . '/auth/v1/authorize?' . $query
        );
    }

    /**
     * Supabase redirects here after Google authentication.
     */
    public function callback(Request $request)
    {
        // Consumed here (not after login) so every exit path — success or
        // failure — answers through the same channel the flow started on.
        $isPopup = $request->session()->pull('supabase_login_popup', false) === true;

        /*
         * Google/Supabase may return an OAuth error.
         */
        if ($request->filled('error')) {
            return $this->loginFailed(
                $isPopup,
                $request->input('error_description', 'Google authentication failed.')
            );
        }

        $code = $request->query('code');

        if (!$code) {
            return $this->loginFailed($isPopup, 'Authentication code was not received.');
        }

        $codeVerifier = $request->session()->pull(
            'supabase_code_verifier'
        );

        if (!$codeVerifier) {
            return $this->loginFailed(
                $isPopup,
                'Authentication session expired. Please try signing in again.'
            );
        }

        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        $supabaseKey = config('services.supabase.key');

        if (!$supabaseUrl || !$supabaseKey) {
            abort(500, 'Supabase authentication is not configured.');
        }

        /*
         * Exchange the authorization code for a Supabase access token.
         */
        $tokenResponse = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Content-Type' => 'application/json',
        ])->post(
            $supabaseUrl . '/auth/v1/token?grant_type=pkce',
            [
                'auth_code' => $code,
                'code_verifier' => $codeVerifier,
            ]
        );

        if (!$tokenResponse->successful()) {
            \Log::error('Supabase token exchange failed', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);

            return $this->loginFailed($isPopup, 'Unable to complete Google authentication.');
        }

        $accessToken = $tokenResponse->json('access_token');

        if (!$accessToken) {
            return $this->loginFailed($isPopup, 'Supabase did not return an access token.');
        }

        /*
         * Ask Supabase for the verified authenticated user.
         */
        $userResponse = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get(
            $supabaseUrl . '/auth/v1/user'
        );

        if (!$userResponse->successful()) {
            \Log::error('Unable to retrieve Supabase user', [
                'status' => $userResponse->status(),
                'body' => $userResponse->body(),
            ]);

            return $this->loginFailed($isPopup, 'Unable to retrieve your Google account.');
        }

        $supabaseUser = $userResponse->json();

        $email = $supabaseUser['email'] ?? null;

        if (!$email) {
            return $this->loginFailed(
                $isPopup,
                'Your Google account did not provide an email address.'
            );
        }

        /*
         * Only permit users that already exist in the PROJ-TRACK database.
         *
         * This is safer for your system because Admin, PMU Staff and
         * Contractor accounts are already managed by PROJ-TRACK.
         */
        $user = User::where('email', $email)->first();

        if (!$user) {
            return $this->loginFailed(
                $isPopup,
                'Your Google account is not registered in PROJ-TRACK.'
            );
        }

        /*
         * Start the normal Laravel authenticated session.
         */
        Auth::login($user, true);

        $request->session()->regenerate();

        /*
         * Redirect according to the existing PROJ-TRACK role.
         *
         * Change "role" below if your User model uses a different
         * field name for roles.
         */
        $redirectUrl = match (strtolower((string) $user->role)) {
            'admin' => route('admin.dashboard'),
            'staff' => route('staff.dashboard'),
            'contractor' => route('contractor.dashboard'),

            default => null,
        };

        if ($redirectUrl === null) {
            return $this->invalidRole($request, $isPopup);
        }

        if ($isPopup) {
            // Hand the dashboard URL to the login tab and close this popup —
            // navigating the popup itself would strand the user on a 500px
            // dashboard with the login tab still sitting behind it.
            return $this->popupResult(true, $redirectUrl, null);
        }

        return redirect($redirectUrl);
    }

    /**
     * One exit path for every login failure: the popup gets the handoff page
     * (so the login tab can show the error), a full-page flow gets the login
     * redirect it always had.
     */
    private function loginFailed(bool $isPopup, string $message)
    {
        if ($isPopup) {
            return $this->popupResult(false, null, $message);
        }

        return redirect()->route('login')->with('error', $message);
    }

    // ================= POPUP RESULT (loaded inside the auth popup) =================
    // Same protocol as AuthController::popupResult: tells the opener (login
    // page) whether sign-in succeeded, hands it the dashboard URL when
    // successful, then closes the popup window.
    private function popupResult(bool $success, ?string $redirect, ?string $error): \Illuminate\Http\Response
    {
        $payload = json_encode([
            'type' => 'google-auth',
            'success' => $success,
            'redirect' => $redirect,
            'error' => $error,
        ]);

        $statusLabel = $success ? 'Sign-in successful.' : 'Sign-in failed.';

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
                <p style="font-size:15px;font-weight:600;margin:0 0 6px">{$statusLabel}</p>
                <p id="popupHint" style="font-size:12px;color:#64748B;margin:0">You can close this window.</p>
            </div>
            <script>
                (function () {
                    var origin = window.location.origin;
                    var result = {$payload};

                    function closePopup() {
                        try { window.close(); } catch (e) { /* ignore */ }
                    }

                    // Primary channel: navigate the opener (login tab) straight
                    // to the dashboard. The login session was already created on
                    // the server and is shared through the session cookie, so the
                    // opener tab is authenticated the moment it loads the URL.
                    if (result.success && result.redirect && window.opener && !window.opener.closed) {
                        try {
                            window.opener.location.href = result.redirect;
                            closePopup();
                            return;
                        } catch (e) {
                            /* fall through to the message channels */
                        }
                    }

                    // Backup channel 1: post a message to the login tab.
                    try {
                        if (window.opener) {
                            window.opener.postMessage(result, origin);
                        }
                    } catch (e) { /* ignore cross-origin / closed-opener errors */ }

                    // Backup channel 2: broadcast across tabs of this origin.
                    try {
                        var bc = new BroadcastChannel('google-auth');
                        bc.postMessage(result);
                        bc.close();
                    } catch (e) { /* BroadcastChannel unsupported */ }

                    // Backup channel 3: localStorage (shared across tabs of this
                    // origin — sessionStorage is per-tab, so the popup writing
                    // there would never reach the opener). The login tab polls
                    // this key (see login.blade.php).
                    try {
                        localStorage.setItem('google-auth-result', JSON.stringify(result));
                    } catch (e) { /* storage unavailable */ }

                    if (!window.opener) {
                        // The popup lost its opener — give the user a way to continue
                        // so they are never stuck on a dead page.
                        var hint = document.getElementById('popupHint');
                        if (result.success && result.redirect) {
                            var a = document.createElement('a');
                            a.href = result.redirect;
                            a.style.cssText = 'color:#2563EB;font-weight:600;text-decoration:underline';
                            a.textContent = 'Continue to dashboard';
                            hint.innerHTML = '';
                            hint.appendChild(a);
                        } else {
                            hint.textContent = result.error || 'Sign-in failed. Please try again.';
                        }
                    }

                    // Delayed close: the backup channels dispatch asynchronously,
                    // so closing synchronously can destroy them before the login
                    // tab receives anything.
                    window.setTimeout(closePopup, 800);
                })();
            </script>
        </body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html');
    }

    private function invalidRole(Request $request, bool $isPopup = false)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your account does not have a valid PROJ-TRACK role.';

        if ($isPopup) {
            return $this->popupResult(false, null, $message);
        }

        return redirect()
            ->route('login')
            ->with('error', $message);
    }
}
