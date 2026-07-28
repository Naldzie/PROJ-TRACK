<?php

namespace App\Support;

use Carbon\Carbon;

class DateHelper
{
    public static function now(): Carbon
    {
        if (app()->environment('production')) {
            return now();
        }

        $simulated = session('simulated_date');

        if ($simulated) {
            return Carbon::parse($simulated);
        }

        return now();
    }
}
