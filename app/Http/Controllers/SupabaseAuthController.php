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
        /*
         * Google/Supabase may return an OAuth error.
         */
        if ($request->filled('error')) {
            return redirect()
                ->route('login')
                ->with(
                    'error',
                    $request->input(
                        'error_description',
                        'Google authentication failed.'
                    )
                );
        }

        $code = $request->query('code');

        if (!$code) {
            return redirect()
                ->route('login')
                ->with('error', 'Authentication code was not received.');
        }

        $codeVerifier = $request->session()->pull(
            'supabase_code_verifier'
        );

        if (!$codeVerifier) {
            return redirect()
                ->route('login')
                ->with(
                    'error',
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

            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Unable to complete Google authentication.'
                );
        }

        $accessToken = $tokenResponse->json('access_token');

        if (!$accessToken) {
            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Supabase did not return an access token.'
                );
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

            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Unable to retrieve your Google account.'
                );
        }

        $supabaseUser = $userResponse->json();

        $email = $supabaseUser['email'] ?? null;

        if (!$email) {
            return redirect()
                ->route('login')
                ->with(
                    'error',
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
            return redirect()
                ->route('login')
                ->with(
                    'error',
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
        return match (strtolower((string) $user->role)) {
            'admin' => redirect()->route('admin.dashboard'),
            'staff' => redirect()->route('staff.dashboard'),
            'contractor' => redirect()->route('contractor.dashboard'),

            default => $this->invalidRole($request),
        };
    }

    private function invalidRole(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with(
                'error',
                'Your account does not have a valid PROJ-TRACK role.'
            );
    }
}
