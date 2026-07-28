<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionTimeout
{
    protected $timeout = 1800; // 30 minutes in seconds

    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $lastActivity = session('last_activity_time');

            // If last activity exists and timeout has passed
            if ($lastActivity && (time() - $lastActivity > $this->timeout)) {
                Auth::logout();
                session()->flush();
                session()->regenerate();

                return redirect()->route('login')
                    ->with('message', 'You have been logged out due to inactivity.');
            }

            // Update last activity timestamp on every request
            session(['last_activity_time' => time()]);
        }

        return $next($request);
    }
}