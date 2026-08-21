<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$console = $app->make(Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('output_buffering', '4096');
ini_set('implicit_flush', '0');

echo 'ob levels at start: '.ob_get_level()."\n";

$u = App\Models\User::where('role', 'admin')->first();
if (! $u) {
    $u = App\Models\User::create(['name' => 'X', 'email' => 'x'.rand(1, 9999).'@x.com', 'role' => 'admin']);
}
$p = App\Models\Project::first() ?? App\Models\Project::create(['title' => 'P', 'status' => 'active', 'user_id' => $u->id]);

$session = $app->make('session')->driver();
$session->start();
$app->make('auth')->guard('web')->setUser($u);

$request = Illuminate\Http\Request::create('/admin/projects/'.$p->id.'/reports/progress.xlsx', 'GET');
$request->setLaravelSession($session);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

// Flush pre-request buffer so only response bytes remain
while (ob_get_level() > 0) {
    ob_end_flush();
}
echo 'ob levels before handle: '.ob_get_level()."\n";

$response = $kernel->handle($request);

// After handle, collect everything PHP has buffered for output
echo 'ob levels after handle: '.ob_get_level()."\n";

// The StreamedResponse writes via its callback; Symfony send() happens in terminate.
// Instead call sendContent ourselves into a fresh buffer.
ob_start();
$response->sendContent();
$body = ob_get_clean();

echo 'captured sendContent length: '.strlen($body)."\n";
echo 'First 12 hex: '.bin2hex(substr($body, 0, 12))."\n";
$pk = strpos($body, 'PK');
echo 'PK at: '.var_export($pk, true)."\n";
file_put_contents('C:\temp\opencode\kernel_real2.xlsx', $body);

if (filesize('C:\temp\opencode\kernel_real2.xlsx') > 0) {
    try {
        $wb = PhpOffice\PhpSpreadsheet\IOFactory::load('C:\temp\opencode\kernel_real2.xlsx');
        echo "VALID XLSX\n";
    } catch (\Throwable $e) {
        echo 'INVALID: '.$e->getMessage()."\n";
    }
} else {
    echo "EMPTY FILE\n";
}
