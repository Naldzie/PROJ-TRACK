<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$p = App\Models\Project::find(142);
if (! $p) {
    echo "Project 142 not found\n";
    exit;
}
echo "id: {$p->id}\n";
echo "title: {$p->title}\n";
echo 'planned_end_date: '.optional($p->planned_end_date)->format('Y-m-d')."\n";
echo 'original_planned_end_date: '.optional($p->original_planned_end_date)->format('Y-m-d')."\n";
echo "extension_count: {$p->extension_count}\n";
echo "status: {$p->status}\n";
echo "\n--- extensions (withTrashed) ---\n";
foreach ($p->extensions()->withTrashed()->get() as $e) {
    echo "#{$e->extension_number} id={$e->id} status={$e->status} new_end={$e->new_end_date?->format('Y-m-d')} orig_end={$e->original_end_date?->format('Y-m-d')} approved_new={$e->approved_new_end_date?->format('Y-m-d')}".($e->deleted_at ? ' TRASHED' : '')."\n";
}
echo "\n--- baseline months ---\n";
foreach (App\Models\ProjectBaselineSchedule::where('project_id', 142)->orderBy('planned_date')->get() as $b) {
    echo "{$b->month_label} date={$b->planned_date} planned={$b->planned_progress_percent} original={$b->original_planned_progress_percent}\n";
}

echo "\n--- adjust endpoint ---\n";
$ctrl = app()->make(App\Http\Controllers\BaselineScheduleController::class);
$resp = $ctrl->getBaselineMonthsForAdjustment($p);
$json = json_decode($resp->getContent(), true);
echo "planned_end_date from endpoint: {$json['planned_end_date']}\n";
foreach ($json['months'] as $m) {
    echo "{$m['month_label']} planned={$m['planned_progress_percent']} orig={$m['original_planned_progress_percent']} locked={$m['has_actual_progress']}\n";
}
