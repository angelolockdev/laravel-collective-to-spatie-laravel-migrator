<?php

nnamespace Fddo\LaravelHtmlMigrator\Commands;

use Fddo\LaravelHtmlMigrator\Converters\CheckboxRadioConverter;
use Fddo\LaravelHtmlMigrator\Converters\InputConverter;
use Fddo\LaravelHtmlMigrator\Converters\LabelButtonConverter;
use Fddo\LaravelHtmlMigrator\Converters\ModelTokenConverter;
use Fddo\LaravelHtmlMigrator\Converters\OpenCloseConverter;
use Fddo\LaravelHtmlMigrator\Converters\SelectConverter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class MigrateHtmlCommand extends Command
{
    protected $signature = 'migrate:html {--apply} {--verbose}';
    protected $description = 'Migrate Laravel Collective HTML syntax to Spatie Laravel HTML syntax.';

    protected $files;
    protected $ambiguousLog = [];
    protected $changed = [];

    protected $converters = [
        OpenCloseConverter::class,
        InputConverter::class,
        LabelButtonConverter::class,
        CheckboxRadioConverter::class,
        SelectConverter::class,
        ModelTokenConverter::class,
    ];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
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

        $converters = array_map(function ($class) {
            return new $class();
        }, $this->converters);

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
                    if ($verbose) $this->line("[WROTE] $file (backup: $bak)");
                } else {
                    $this->line("[DRY] Would modify: $file");
                }
            } else {
                if ($verbose) $this->line("[SKIP] No change: $file");
            }
        }

        $this->files->put(base_path('ambiguous.log'), json_encode($this->ambiguousLog, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

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