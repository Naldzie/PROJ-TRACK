<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Dev-only trigger to run the notification scan from the browser so the
 * Date Simulator (SimulateDate middleware → Carbon::setTestNow) can drive
 * deadline/overdue notifications end-to-end.
 *
 * Non-production only (routes/web.php dev block).
 */
class DevNotificationController extends Controller
{
    public function scan(Request $request)
    {
        Artisan::call('notifications:scan');

        return response()->json([
            'success' => true,
            'output'  => trim(Artisan::output()),
        ]);
    }
}
