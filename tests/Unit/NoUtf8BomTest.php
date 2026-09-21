<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ============================================================================
 * NoUtf8BomTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Fails when any source file starts with a UTF-8 byte-order mark (EF BB BF).
 *
 * Why it exists (2026-09-21, textfiles/project_report_overhaul_plan.md P0)
 * -----------------------------------------------------------------------
 * bootstrap/app.php had been saved with a BOM. PHP echoes bytes that sit
 * before "<?php", so EVERY HTTP response began with EF BB BF — including the
 * downloaded .xlsx (a ZIP: Excel then calls it corrupt) and the summary PDF.
 * Feature tests could not see it: the bytes are echoed while the framework
 * boots, outside the Response object the tests inspect. So the guard checks
 * the files themselves.
 *
 * Run With
 * --------
 *   php artisan test --filter=NoUtf8BomTest
 */
class NoUtf8BomTest extends TestCase
{
    /**
     * Directories whose files are loaded by PHP or served to browsers.
     */
    private const DIRS = [
        'app', 'bootstrap', 'config', 'database', 'routes', 'lang',
        'resources', 'public/js', 'public/css', 'tests',
    ];

    /**
     * Extensions worth checking (PHP, Blade, and served text assets).
     */
    private const EXTENSIONS = ['php', 'js', 'mjs', 'css', 'json'];

    /**
     * ------------------------------------------------------------------------
     * No source file starts with a UTF-8 BOM
     * ------------------------------------------------------------------------
     */
    public function test_no_source_file_starts_with_a_utf8_bom(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $checked = 0;

        foreach (self::DIRS as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                // bootstrap/cache holds generated files, not sources
                if (str_contains($file->getPathname(), 'bootstrap' . DIRECTORY_SEPARATOR . 'cache')) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    continue;
                }

                $checked++;
                $handle = fopen($file->getPathname(), 'rb');
                $head = fread($handle, 3);
                fclose($handle);

                if ($head === "\xEF\xBB\xBF") {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        // Guard against a vacuous pass (wrong root, empty scan)
        $this->assertGreaterThan(100, $checked, 'The scan found too few files — wrong root?');

        $this->assertSame(
            [],
            $offenders,
            "These files start with a UTF-8 BOM (re-save them as UTF-8 without BOM):\n"
                . implode("\n", $offenders)
        );
    }
}
