<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $service = $app->make(App\Services\ProjectDetailService::class);
    $project = App\Models\Project::find(7);
    $data = $service->prepareProjectData($project);

    $ai = $data['ai_prediction'];
    echo "=== Controller Simulation ===\n";
    echo "Status: " . ($ai['status'] ?? 'MISSING') . "\n";
    echo "Risk: " . ($ai['risk_level'] ?? 'MISSING') . "\n";
    echo "Progress: " . ($ai['current_progress'] ?? 'MISSING') . "%\n";
    echo "Regression: " . ($ai['regression_info']['equation'] ?? 'MISSING') . "\n";
    echo "R²: " . ($ai['regression_info']['r_squared'] ?? 'MISSING') . "\n";
    echo "Forecast count: " . count($ai['forecast_data'] ?? []) . "\n";
    echo "Assessment count: " . count($ai['assessment_data'] ?? []) . "\n";
    echo "Keys: " . count($ai) . "\n";
    echo "CONTROLLER TEST PASSED\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "CONTROLLER TEST FAILED\n";
}
