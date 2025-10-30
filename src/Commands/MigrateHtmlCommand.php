<?php

namespace Fddo\LaravelHtmlMigrator\Commands;

use DateTimeImmutable;
use Fddo\LaravelHtmlMigrator\Converters\CheckboxRadioConverter;
use Fddo\LaravelHtmlMigrator\Converters\InputConverter;
use Fddo\LaravelHtmlMigrator\Converters\LabelButtonConverter;
use Fddo\LaravelHtmlMigrator\Converters\ModelTokenConverter;
use Fddo\LaravelHtmlMigrator\Converters\OpenCloseConverter;
use Fddo\LaravelHtmlMigrator\Converters\SelectConverter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrateHtmlCommand extends Command
{
    protected $signature = 'migrate:html {--apply} {--verbose} {--focus=} {--rollback=}';
    protected $description = 'Migrate Laravel Collective HTML syntax to Spatie Laravel HTML syntax.';

    protected $files;
    protected $ambiguousLog = [];
    protected $changed = [];

    protected $converters = [
        'OPEN' => OpenCloseConverter::class,
        'CLOSE' => OpenCloseConverter::class,
        'INPUTS' => InputConverter::class,
        'LABELS' => LabelButtonConverter::class,
        'BUTTONS' => LabelButtonConverter::class,
        'CHECKBOX' => CheckboxRadioConverter::class,
        'RADIO' => CheckboxRadioConverter::class,
        'SELECTS' => SelectConverter::class,
        'MODEL' => ModelTokenConverter::class,
        'TOKEN' => ModelTokenConverter::class,
    ];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        if ($this->option('rollback')) {
            $this->performRollback();
            return 0;
        }

        $viewsDir = resource_path('views');
        if (!$this->files->isDirectory($viewsDir)) {
            $this->error("Error: resources/views not found at expected path: $viewsDir");
            return 2;
        }

        $apply = $this->option('apply');
        $verbose = $this->option('verbose');
        $dryRun = !$apply;

        $this->info("Target: $viewsDir");
        $this->info($dryRun ? "Mode: DRY-RUN (no files will be written)" : "Mode: APPLY (files will be modified, backups created)");

        $bladeFiles = $this->getBladeFiles($viewsDir);
        if (empty($bladeFiles)) {
            $this->info("No blade files found under resources/views.");
            return 0;
        }

        $converters = $this->getConverters();

        $timestamp = (new DateTimeImmutable('now'))->format('Ymd\THis');
        $manifest = [
            'timestamp' => $timestamp,
            'files' => [],
            'options' => [
                'apply' => $apply,
                'focus' => $this->option('focus'),
            ],
        ];

        foreach ($bladeFiles as $file) {
            $orig = $this->files->get($file);
            $content = $orig;

            foreach ($converters as $converter) {
                $content = $converter->convert($content, $file);
                $this->ambiguousLog = array_merge($this->ambiguousLog, $converter->getAmbiguousLogs());
            }

            if ($content !== $orig) {
                $this->changed[$file] = ['before' => $orig, 'after' => $content];
                if ($apply) {
                    $bak = $file . '.bak';
                    if (!$this->files->exists($bak)) $this->files->copy($file, $bak);
                    $this->files->put($file, $content);
                    $manifest['files'][] = ['file' => $file, 'bak' => $bak];
                    if ($verbose) $this->line("[WROTE] $file (backup: $bak)");
                } else {
                    $this->line("[DRY] Would modify: $file");
                }
            }
            else {
                if ($verbose) $this->line("[SKIP] No change: $file");
            }
        }

        $this->files->put(base_path('ambiguous.log'), json_encode($this->ambiguousLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($apply) {
            $manifestFilename = base_path("convert_manifest_{$timestamp}.json");
            $this->files->put($manifestFilename, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->files->put(base_path('convert_manifest_latest.json'), json_encode(['manifest' => basename($manifestFilename), 'timestamp' => $timestamp], JSON_PRETTY_PRINT));
            $this->info("Manifest written: " . basename($manifestFilename));
        }

        $this->info("\nSummary:");
        $this->info(" - Blade files scanned: " . count($bladeFiles));
        $this->info(" - Files changed: " . count($this->changed));
        $this->info(" - Ambiguous occurrences: " . count($this->ambiguousLog) . " (see ambiguous.log)");

        if ($dryRun && count($this->changed) > 0) {
            $this->info("\nSample diffs (first 5 files):");
            $i = 0;
            foreach ($this->changed as $path => $pair) {
                $this->info("\n== $path ==");
                $this->showSnippetDiff($pair['before'], $pair['after']);
                if (++$i >= 5) break;
            }
            $this->info("\nRun with --apply to write changes.");
        } elseif (!$dryRun) {
            $this->info("\nModifications appliquées. Vérifie les .bak et exécute tes tests.");
        }

        return 0;
    }

    private function getConverters(): array
    {
        $focus = $this->option('focus');
        if (!$focus) {
            return array_map(function ($class) {
                return new $class();
            }, array_unique(array_values($this->converters)));
        }

        $focusSet = array_map('strtoupper', array_map('trim', explode(',', $focus)));
        $converters = [];
        foreach ($focusSet as $token) {
            if (isset($this->converters[$token])) {
                $class = $this->converters[$token];
                if (!isset($converters[$class])) {
                    $converters[$class] = new $class();
                }
            }
        }
        return array_values($converters);
    }

    private function performRollback()
    {
        $rollbackArg = $this->option('rollback');
        $manifestLatestPath = base_path('convert_manifest_latest.json');

        if ($rollbackArg === 'latest') {
            if (!$this->files->exists($manifestLatestPath)) {
                $this->error("No latest manifest file found ($manifestLatestPath)");
                return;
            }
            $meta = json_decode($this->files->get($manifestLatestPath), true);
            if (empty($meta['manifest'])) {
                $this->error("Malformed latest manifest pointer");
                return;
            }
            $manifestFile = base_path($meta['manifest']);
        } else {
            $manifestFile = base_path($rollbackArg);
        }

        if (!$this->files->exists($manifestFile)) {
            $this->error("Manifest file not found: $manifestFile");
            return;
        }

        $this->info("Rollback using manifest: $manifestFile");
        $manifest = json_decode($this->files->get($manifestFile), true);
        if (empty($manifest['files'])) {
            $this->info("Nothing to rollback in manifest.");
            return;
        }

        $restored = 0;
        foreach ($manifest['files'] as $entry) {
            $file = $entry['file'];
            $bak = $entry['bak'];
            if (!$this->files->exists($bak)) {
                $this->error("Backup not found for $file: $bak");
                continue;
            }
            if (!$this->files->copy($bak, $file)) {
                $this->error("Failed to restore $file from $bak");
                continue;
            }
            $restored++;
            if ($this->option('verbose')) $this->line("[RESTORED] $file from $bak");
        }

        $this->info("Rollback complete. Restored $restored files.");
    }

    private function getBladeFiles($dir)
    {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $files = [];
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $path = $f->getPathname();
            if (substr($path, -10) === '.blade.php') $files[] = $path;
        }
        return $files;
    }

    private function showSnippetDiff($before, $after, $lines = 6)
    {
        $bLines = preg_split("/\R/", $before);
        $aLines = preg_split("/\R/", $after);
        $len = max(count($bLines), count($aLines));
        $firstDiff = null;
        for ($i=0;$i<$len;$i++) {
            $b = $bLines[$i] ?? '';
            $a = $aLines[$i] ?? '';
            if ($b !== $a) { $firstDiff = max(0, $i-2); break; }
        }
        if ($firstDiff === null) {
            $this->line("(no textual diff detected)");
            return;
        }
        for ($i=$firstDiff; $i<$firstDiff+$lines && $i<$len; $i++) {
            $b = $bLines[$i] ?? '';
            $a = $aLines[$i] ?? '';
            if ($b !== $a) {
                $this->line("- " . substr($b,0,200));
                $this->line("+ " . substr($a,0,200));
            } else {
                $this->line("  " . substr($a,0,200));
            }
        }
    }
}
