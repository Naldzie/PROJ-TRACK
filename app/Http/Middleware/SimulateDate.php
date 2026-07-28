<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;

class SimulateDate
{
    public function handle($request, Closure $next)
    {
        $simulated = session('simulated_date');

        if ($simulated && !app()->environment('production')) {
            Carbon::setTestNow(Carbon::parse($simulated));
        }

        $response = $next($request);

        Carbon::setTestNow();

        return $response;
    }
}
