<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogSuccessfulLogin
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
public function handle($event)
{
    ActivityLog::create([
        'user_id' => $event->user->id,
        'action' => 'login',
        'visibility' => 'admin_only',
        'logged_in_at' => Carbon::now('Asia/Manila'),
    ]);
}
}
