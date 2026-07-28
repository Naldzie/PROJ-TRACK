<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DevDateController extends Controller
{
    public function get()
    {
        return response()->json([
            'simulated_date' => session('simulated_date'),
            'real_date' => now()->format('Y-m-d'),
        ]);
    }

    public function set(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        session(['simulated_date' => $request->date]);

        Carbon::setTestNow(Carbon::parse($request->date));

        return response()->json([
            'simulated_date' => $request->date,
        ]);
    }

    public function clear()
    {
        session()->forget('simulated_date');

        Carbon::setTestNow();

        return response()->json([
            'simulated_date' => null,
        ]);
    }
}
