<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Staff;
use App\Models\Admin;
use App\Models\Contractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupabaseAuthController extends Controller
{
    /**
     * Normal Google login.
     *
     * Also requests Google Calendar permission so the same Google/Supabase
     * authorization can later be used by PROJ-TRACK for Calendar.
     */
    public function redirect(Request $request)
    {
        return $this->startGoogleFlow($request, false);
    }

    /**
     * Connect/reconnect Google Calendar for an already logged-in user.
     *
     * This goes through Supabase instead of sending Azure directly to Google.
     */
    public function calendarConnect(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        return $this->startGoogleFlow($request, true);
    }

    /**
     * Start Supabase -> Google OAuth.
     */
    private function startGoogleFlow(Request $request, bool $calendarOnly)
    {
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $redirectUri = (string) config('services.supabase.redirect');

        if ($supabaseUrl === '' || $redirectUri === '') {
            abort(500, 'Supabase authentication is not configured.');
        }

        /*
         * Login popup handling.
         */
        if (!$calendarOnly && $request->query('popup') === '1') {
            $request->session()->put('supabase_login_popup', true);
        } else {
            $request->session()->forget('supabase_login_popup');
        }

        /*
         * Remember whether the flow was started specifically from
         * "Connect Google Calendar".
         */
        $request->session()->put(
            'supabase_calendar_connect',
            $calendarOnly
        );

        /*
         * PKCE verifier/challenge.
         */
        $codeVerifier = Str::random(96);

        $codeChallenge = rtrim(
            strtr(
                base64_encode(
                    hash('sha256', $codeVerifier, true)
                ),
                '+/',
                '-_'
            ),
            '='
        );

        $request->session()->put(
            'supabase_code_verifier',
            $codeVerifier
        );

        /*
         * Supabase's /authorize endpoint accepts scopes as a
         * space-separated string.
         *
         * access_type=offline + prompt=consent asks Google for a
         * provider refresh token.
         */
        $query = http_build_query([
            'provider' => 'google',

            'redirect_to' => $redirectUri,

            'scopes' => implode(' ', [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/calendar.events',
            ]),

            'access_type' => 'offline',
            'prompt' => 'consent',

            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 's256',
        ]);

        return redirect(
            $supabaseUrl . '/auth/v1/authorize?' . $query
        );
    }

    /**
     * Supabase callback after Google authorization.
     */
    public function callback(Request $request)
    {
        $isPopup = $request->session()
            ->pull('supabase_login_popup', false) === true;

        $calendarOnly = $request->session()
            ->pull('supabase_calendar_connect', false) === true;

        /*
         * OAuth error from Google/Supabase.
         */
        if ($request->filled('error')) {
            $message = $request->input(
                'error_description',
                'Google authentication failed.'
            );

            if ($calendarOnly && Auth::check()) {
                return redirect()
                    ->route($this->dashboardRoute(Auth::user()))
                    ->with('error', $message);
            }

            return $this->loginFailed($isPopup, $message);
        }

        $code = $request->query('code');

        if (!$code) {
            return $this->loginFailed(
                $isPopup,
                'Authentication code was not received.'
            );
        }

        /*
         * Retrieve PKCE verifier.
         */
        $codeVerifier = $request->session()
            ->pull('supabase_code_verifier');

        if (!$codeVerifier) {
            return $this->loginFailed(
                $isPopup,
                'Authentication session expired. Please try again.'
            );
        }

        $supabaseUrl = rtrim(
            (string) config('services.supabase.url'),
            '/'
        );

        $supabaseKey = (string) config(
            'services.supabase.key'
        );

        if ($supabaseUrl === '' || $supabaseKey === '') {
            abort(500, 'Supabase authentication is not configured.');
        }

        /*
         * Exchange PKCE authorization code.
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
            Log::error('Supabase token exchange failed', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);

            return $this->loginFailed(
                $isPopup,
                'Unable to complete Google authentication.'
            );
        }

        /*
         * Supabase token.
         */
        $supabaseAccessToken = $tokenResponse->json(
            'access_token'
        );

        /*
         * Actual Google OAuth tokens.
         *
         * These are the tokens needed for Google Calendar API.
         */
        $googleAccessToken = $tokenResponse->json(
            'provider_token'
        );

        $googleRefreshToken = $tokenResponse->json(
            'provider_refresh_token'
        );

        if (!$supabaseAccessToken) {
            return $this->loginFailed(
                $isPopup,
                'Supabase did not return an access token.'
            );
        }

        /*
         * Retrieve verified Supabase user.
         */
        $userResponse = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Authorization' =>
                'Bearer ' . $supabaseAccessToken,
        ])->get(
            $supabaseUrl . '/auth/v1/user'
        );

        if (!$userResponse->successful()) {
            Log::error(
                'Unable to retrieve Supabase user',
                [
                    'status' => $userResponse->status(),
                    'body' => $userResponse->body(),
                ]
            );

            return $this->loginFailed(
                $isPopup,
                'Unable to retrieve your Google account.'
            );
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
         * Only existing PROJ-TRACK users may log in.
         */
        $user = User::where('email', $email)->first();

        if (!$user) {
            return $this->loginFailed(
                $isPopup,
                'Your Google account is not registered in PROJ-TRACK.'
            );
        }

        /*
         * Staff and admin may only sign in from a desktop or laptop.
         * Contractors are the only role with mobile access. This runs
         * before Auth::login so a blocked sign-in never creates a session.
         * (Calendar-only reconnects are skipped: that user is already
         * authenticated and is not gaining access here.)
         */
        if (
            !$calendarOnly
            && in_array(
                strtolower((string) $user->role),
                ['admin', 'staff'],
                true
            )
            && self::isMobileDevice($request)
        ) {
            return $this->loginFailed(
                $isPopup,
                'Please use a desktop or laptop to access PROJ-TRACK. Mobile access is for contractors only.'
            );
        }

        /*
         * If this is "Connect Calendar", make sure the Google account
         * belongs to the currently logged-in PROJ-TRACK account.
         */
        if (
            $calendarOnly &&
            Auth::check() &&
            strtolower(Auth::user()->email) !== strtolower($email)
        ) {
            return redirect()
                ->route($this->dashboardRoute(Auth::user()))
                ->with(
                    'error',
                    'Please connect the same Google account used by your PROJ-TRACK account.'
                );
        }

        /*
         * Google profile information from Supabase.
         */
        $metadata =
            $supabaseUser['user_metadata'] ?? [];

        $fullName =
            $metadata['full_name']
            ?? $metadata['name']
            ?? null;

        $avatarUrl =
            $metadata['avatar_url']
            ?? $metadata['picture']
            ?? null;

        $updates = [];

        if (
            is_string($fullName) &&
            trim($fullName) !== ''
        ) {
            $updates['name'] = trim($fullName);
        }

        if (
            is_string($avatarUrl) &&
            trim($avatarUrl) !== ''
        ) {
            $currentPhoto = $user->profile_photo;

            if (
                !$currentPhoto ||
                str_starts_with(
                    $currentPhoto,
                    'http'
                )
            ) {
                $updates['profile_photo'] =
                    trim($avatarUrl);
            }
        }

        /*
         * Store Google Calendar tokens.
         *
         * IMPORTANT:
         * These database columns need to exist.
         */
        if ($googleAccessToken) {
            $updates['google_access_token'] =
                encrypt($googleAccessToken);

            /*
             * Google access tokens are normally around 1 hour.
             */
            $updates['google_token_expires_at'] =
                now()->addSeconds(3500);
        }

        /*
         * Google may only return the refresh token on the first consent,
         * so never overwrite an existing one with null.
         */
        if ($googleRefreshToken) {
            $updates['google_refresh_token'] =
                encrypt($googleRefreshToken);
        }

        if ($updates !== []) {
            $user->update($updates);
        }

        /*
         * Calendar-only connection:
         * the user is already authenticated.
         */
        if ($calendarOnly && Auth::check()) {
            return redirect()
                ->route($this->dashboardRoute($user))
                ->with(
                    'success',
                    'Google Calendar connected successfully.'
                );
        }

        /*
         * Normal application login.
         */
        Auth::login($user, true);

        $request->session()->regenerate();

        /*
         * Keep role-specific profile information synchronized.
         */
        if (
            $fullName &&
            $user->role === 'admin'
        ) {
            Admin::updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'full_name' => $fullName,
                ]
            );
        }

        if (
            $fullName &&
            $user->role === 'staff'
        ) {
            $nameParts = explode(
                ' ',
                trim($fullName)
            );

            $staff = Staff::firstOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'staff_id' =>
                        'STAFF-' . rand(1000, 9999),
                ]
            );

            $staff->update([
                'first_name' =>
                    $nameParts[0] ?? null,

                'last_name' =>
                    count($nameParts) > 1
                        ? end($nameParts)
                        : null,
            ]);
        }

        if (
            $fullName &&
            $user->role === 'contractor'
        ) {
            Contractor::updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'contractor_name' =>
                        $fullName,
                ]
            );
        }

        $dashboardRoute =
            $this->dashboardRoute($user);

        if (!$dashboardRoute) {
            return $this->invalidRole(
                $request,
                $isPopup
            );
        }

        $redirectUrl = route(
            $dashboardRoute
        );

        if ($isPopup) {
            return $this->popupResult(
                true,
                $redirectUrl,
                null
            );
        }

        return redirect($redirectUrl);
    }

    /**
     * Whether the request comes from a mobile device (phone or tablet),
     * judged from the User-Agent. Desktop browsers — including a phone's
     * "desktop site" mode, which sends a desktop UA — are not mobile here.
     */
    private static function isMobileDevice(Request $request): bool
    {
        $userAgent = (string) $request->header('User-Agent', '');

        if ($userAgent === '') {
            return false;
        }

        return (bool) preg_match(
            '/android|iphone|ipod|ipad|mobile|blackberry|iemobile|opera mini|windows phone/i',
            $userAgent
        );
    }

    /**
     * Return dashboard route name according to role.
     */
    private function dashboardRoute($user): ?string
    {
        return match (
            strtolower((string) $user->role)
        ) {
            'admin' =>
                'admin.dashboard',

            'staff' =>
                'staff.dashboard',

            'contractor' =>
                'contractor.dashboard',

            default => null,
        };
    }

    /**
     * Login failure handler.
     */
    private function loginFailed(
        bool $isPopup,
        string $message
    ) {
        if ($isPopup) {
            return $this->popupResult(
                false,
                null,
                $message
            );
        }

        return redirect()
            ->route('login')
            ->with(
                'error',
                $message
            );
    }

    /**
     * Popup result page.
     */
    private function popupResult(
        bool $success,
        ?string $redirect,
        ?string $error
    ): \Illuminate\Http\Response {
        $payload = json_encode([
            'type' => 'google-auth',
            'success' => $success,
            'redirect' => $redirect,
            'error' => $error,
        ]);

        $statusLabel =
            $success
                ? 'Sign-in successful.'
                : 'Sign-in failed.';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Sign-in complete</title>
</head>
<body style="
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#F8FAFF;
    font-family:'Segoe UI',sans-serif;
    color:#172033;
">
<div style="text-align:center;padding:0 24px">
    <p style="font-size:15px;font-weight:600;margin:0 0 6px">
        {$statusLabel}
    </p>

    <p id="popupHint"
       style="font-size:12px;color:#64748B;margin:0">
        You can close this window.
    </p>
</div>

<script>
(function () {
    var origin = window.location.origin;
    var result = {$payload};

    function closePopup() {
        try {
            window.close();
        } catch (e) {}
    }

    if (
        result.success &&
        result.redirect &&
        window.opener &&
        !window.opener.closed
    ) {
        try {
            window.opener.location.href =
                result.redirect;

            closePopup();

            return;
        } catch (e) {}
    }

    try {
        if (window.opener) {
            window.opener.postMessage(
                result,
                origin
            );
        }
    } catch (e) {}

    try {
        var bc =
            new BroadcastChannel(
                'google-auth'
            );

        bc.postMessage(result);

        bc.close();
    } catch (e) {}

    try {
        localStorage.setItem(
            'google-auth-result',
            JSON.stringify(result)
        );
    } catch (e) {}

    if (!window.opener) {
        var hint =
            document.getElementById(
                'popupHint'
            );

        if (
            result.success &&
            result.redirect
        ) {
            var a =
                document.createElement('a');

            a.href =
                result.redirect;

            a.style.cssText =
                'color:#2563EB;' +
                'font-weight:600;' +
                'text-decoration:underline';

            a.textContent =
                'Continue to dashboard';

            hint.innerHTML = '';

            hint.appendChild(a);
        } else {
            hint.textContent =
                result.error ||
                'Sign-in failed. Please try again.';
        }
    }

    window.setTimeout(
        closePopup,
        800
    );
})();
</script>

</body>
</html>
HTML;

        return response($html)
            ->header(
                'Content-Type',
                'text/html'
            );
    }

    /**
     * Invalid user role.
     */
    private function invalidRole(
        Request $request,
        bool $isPopup = false
    ) {
        Auth::logout();

        $request->session()
            ->invalidate();

        $request->session()
            ->regenerateToken();

        $message =
            'Your account does not have a valid PROJ-TRACK role.';

        if ($isPopup) {
            return $this->popupResult(
                false,
                null,
                $message
            );
        }

        return redirect()
            ->route('login')
            ->with(
                'error',
                $message
            );
    }
}
