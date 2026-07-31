<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Events\Login;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Project;
use App\Policies\CategoryPolicy;
use App\Policies\ProjectPolicy;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Category::class => CategoryPolicy::class,
        Project::class => ProjectPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();

        Event::listen(Login::class, function ($event) {
            ActivityLog::create([
                'user_id' => $event->user->id,
                'action' => 'Login',
                'description' => $event->user->name . ' logged in',
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);
        });
    }

    protected function registerPolicies()
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
