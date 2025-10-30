<?php

namespace Fddo\LaravelHtmlMigrator\Commands;

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

        foreach ($bladeFiles as $file) {
            $orig = $this->files->get($file);
            $content = $orig;

            $content = $this->convertOpenClose($content, $file);
            $content = $this->convertInputs($content, $file);
            $content = $this->convertLabelsButtons($content, $file);
            $content = $this->convertCheckboxRadio($content, $file);
            $content = $this->convertSelects($content, $file);
            $content = $this->convertModelToken($content, $file);

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

    private function logAmbiguous($file, $lineOrOffset, $snippet, $reason)
    {
        $this->ambiguousLog[] = ['file' => $file, 'pos' => $lineOrOffset, 'snippet' => substr($snippet, 0, 200), 'reason' => $reason];
    }

    private function extractParenthesized($content, $startParen)
    {
        $len = strlen($content);
        if ($content[$startParen] !== '(') return null;
        $depth = 0;
        $inString = false; $stringChar = null; $escaped = false;
        for ($i = $startParen; $i < $len; $i++) {
            $ch = $content[$i];
            if ($inString) {
                if ($escaped) { $escaped = false; }
                elseif ($ch === "\\") { $escaped = true; }
                elseif ($ch === $stringChar) { $inString = false; $stringChar = null; }
                continue;
            } else {
                if ($ch === '\'' || $ch === '"') { $inString = true; $stringChar = $ch; continue; }
                if ($ch === '(') $depth++;
                elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $between = substr($content, $startParen + 1, $i - $startParen - 1);
                        return [$between, $i];
                    }
                }
            }
        }
        return null;
    }

    private function splitTopLevelArgs($s)
    {
        $len = strlen($s);
        $args = []; $buf = ''; $depth = 0;
        $inString = false; $stringChar = null; $escaped = false;
        for ($i=0;$i<$len;$i++) {
            $ch = $s[$i];
            if ($inString) {
                $buf .= $ch;
                if ($escaped) { $escaped = false; } elseif ($ch === "\\") { $escaped = true; } elseif ($ch === $stringChar) { $inString = false; $stringChar = null; }
                continue;
            } else {
                if ($ch === '\'' || $ch === '"') { $inString = true; $stringChar = $ch; $buf .= $ch; continue; }
                if ($ch === '[' || $ch === '(' || $ch === '{') { $depth++; $buf .= $ch; continue; }
                if ($ch === ']' || $ch === ')' || $ch === '}') { $depth--; $buf .= $ch; continue; }
                if ($ch === ',' && $depth === 0) { $args[] = trim($buf); $buf = ''; continue; }
                $buf .= $ch;
            }
        }
        if (strlen(trim($buf))>0) $args[] = trim($buf);
        return $args;
    }

    private function tryEvalArrayLiteral($s)
    {
        if (stripos($s, 'function') !== false || stripos($s, '<?') !== false) return null;
        $code = "<?php\nreturn " . $s . ";\n";
        try {
            return (function() use ($code) { return eval($code); })();
        } catch (\Throwable $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function convertOpenClose($content, $file)
    {
        $content = preg_replace('/\bForm::close\s*\(\s*\)\s*;?/i', 'html()->form()->close();', $content);
        $offset = 0;
        while (($pos = stripos($content, 'Form::open', $offset)) !== false) {
            $openParen = strpos($content, '(', $pos);
            if ($openParen === false) { $offset = $pos + 9; continue; }
            $ex = $this->extractParenthesized($content, $openParen);
            if ($ex === null) { $this->logAmbiguous($file, $pos, substr($content, $pos, 80), "unbalanced parentheses in Form::open"); $offset = $pos + 9; continue; }
            list($between, $endPos) = $ex;
            $replacement = $this->buildFormOpenReplacement($between, $file, $pos);
            if ($replacement === null) { $offset = $endPos + 1; continue; }
            $after = substr($content, $endPos + 1, 2);
            $semi = ''; if (preg_match('/^\s*;/', $after)) $semi = ';';
            $content = substr($content, 0, $pos) . $replacement . $semi . substr($content, $endPos + 1 + strlen($semi));
            $offset = $pos + strlen($replacement);
        }
        return $content;
    }

    private function buildFormOpenReplacement($argString, $file, $pos)
    {
        $trim = trim($argString);
        if ($trim === '') return "html()->form()->open()";
        $args = $this->splitTopLevelArgs($trim);
        if (count($args) === 1 && !preg_match('/^\s*\[/', $trim)) {
            $action = $args[0];
            return "html()->form('POST', {$action})->open()";
        }
        if (preg_match('/^\s*\[/', $trim)) {
            $parsed = $this->tryEvalArrayLiteral($trim);
            if (is_array($parsed)) {
                $method = strtoupper($parsed['method'] ?? ($parsed['type'] ?? 'POST'));
                $actionExpr = 'null';
                if (array_key_exists('route', $parsed) && $parsed['route'] !== null) {
                    if (is_string($parsed['route'])) $actionExpr = "route('" . addslashes($parsed['route']) . "')";
                    elseif (is_array($parsed['route']) && isset($parsed['route'][0]) && is_string($parsed['route'][0])) {
                        $name = array_shift($parsed['route']);
                        $argCodes = [];
                        foreach ($parsed['route'] as $a) $argCodes[] = var_export($a, true);
                        $actionExpr = "route('" . addslashes($name) . "'" . (empty($argCodes) ? '' : (", [" . implode(', ', $argCodes) . "]")) . ")";
                    } else $actionExpr = 'null';
                } elseif (array_key_exists('action', $parsed) && is_string($parsed['action'])) {
                    $actionExpr = "'" . addslashes($parsed['action']) . "'";
                } elseif (array_key_exists('url', $parsed) && is_string($parsed['url'])) {
                    $actionExpr = "'" . addslashes($parsed['url']) . "'";
                }

                $attrs = [];
                if (isset($parsed['accept-charset'])) $attrs['accept-charset'] = $parsed['accept-charset'];
                elseif (isset($parsed['accept_charset'])) $attrs['accept-charset'] = $parsed['accept_charset'];
                if (array_key_exists('novalidate', $parsed)) {
                    $nv = $parsed['novalidate'];
                    if ($nv === true || $nv === 'novalidate' || $nv === 1) $attrs['novalidate'] = 'novalidate';
                    elseif (is_string($nv)) $attrs['novalidate'] = $nv;
                }
                if (isset($parsed['files']) && ($parsed['files'] === true || $parsed['files'] === 'true')) $attrs['enctype'] = 'multipart/form-data';
                foreach (['class','id','style','role','accept'] as $k) if (isset($parsed[$k])) $attrs[$k] = $parsed[$k];

                $attrCode = $this->buildAttributesCode($attrs);
                if ($actionExpr === 'null' && $attrCode === '[]' && $method === 'POST') return "html()->form()->open()";
                return "html()->form('{$method}', {$actionExpr})->attributes({$attrCode})->open()";
            } else {
                $this->logAmbiguous($file, $pos, $argString, "complex array literal (skipped)");
                return null;
            }
        }
        $this->logAmbiguous($file, $pos, $argString, "unknown Form::open args (skipped)");
        return null;
    }

    private function convertInputs($content, $file)
    {
        $fns = ['text','email','password','file','hidden'];
        foreach ($fns as $fn) {
            $offset = 0;
            while (($pos = stripos($content, "Form::{$fn}(", $offset)) !== false) {
                $open = strpos($content, '(', $pos);
                if ($open === false) { $offset = $pos + 6; continue; }
                $ex = $this->extractParenthesized($content, $open);
                if ($ex === null) { $this->logAmbiguous($file, $pos, substr($content,$pos,80), "unbalanced parenthesis in Form::{$fn}"); $offset = $pos + 6; continue; }
                list($between, $endPos) = $ex;
                $args = $this->splitTopLevelArgs($between);
                $name = $args[0] ?? "''";
                $value = $args[1] ?? null;
                $attrs = $args[2] ?? null;

                $rep = null;
                if (in_array($fn, ['password','file'])) {
                    $rep = "html()->{$fn}({$name})";
                    if ($attrs !== null) $rep .= "->attributes({$attrs})";
                } elseif ($fn === 'hidden') {
                    $rep = "html()->hidden({$name})";
                    if ($value !== null) $rep .= "->value({$value})";
                } else {
                    $rep = "html()->{$fn}({$name})";
                    if ($value !== null && trim($value) !== '') $rep .= "->value({$value})";
                    if ($attrs !== null) $rep .= "->attributes({$attrs})";
                }

                $after = substr($content, $endPos + 1, 2); $semi = ''; if (preg_match('/^\s*;/', $after)) $semi = ';';
                $content = substr($content, 0, $pos) . $rep . $semi . substr($content, $endPos + 1 + strlen($semi));
                $offset = $pos + strlen($rep);
            }
        }
        return $content;
    }

    private function convertLabelsButtons($content, $file)
    {
        $offset = 0;
        while (($pos = stripos($content, "Form::label(", $offset)) !== false) {
            $open = strpos($content, '(', $pos);
            $ex = $this->extractParenthesized($content, $open);
            if ($ex === null) { $this->logAmbiguous($file,$pos,substr($content,$pos,80),"unbalanced label()"); $offset=$pos+8; continue; }
            list($between,$endPos) = $ex;
            $args = $this->splitTopLevelArgs($between);
            $name = $args[0] ?? "''";
            $text = $args[1] ?? "null";
            $attrs = $args[2] ?? null;
            $rep = "html()->label()->for({$name})";
            if ($text !== "null") $rep .= "->text({$text})";
            if ($attrs !== null) $rep .= "->attributes({$attrs})";
            $after = substr($content, $endPos + 1, 2); $semi=''; if (preg_match('/^\s*;/', $after)) $semi=';';
            $content = substr($content,0,$pos) . $rep . $semi . substr($content,$endPos+1+strlen($semi));
            $offset = $pos + strlen($rep);
        }

        foreach (['submit','button'] as $fn) {
            $offset = 0;
            while (($pos = stripos($content, "Form::{$fn}(", $offset)) !== false) {
                $open = strpos($content, '(', $pos);
                $ex = $this->extractParenthesized($content, $open);
                if ($ex === null) { $this->logAmbiguous($file,$pos,substr($content,$pos,80),"unbalanced {$fn}()"); $offset=$pos+6; continue; }
                list($between,$endPos) = $ex;
                $args = $this->splitTopLevelArgs($between);
                $value = $args[0] ?? "'Submit'";
                $attrs = $args[1] ?? null;
                $rep = "html()->button({$value})->type('submit')";
                if ($attrs !== null) $rep .= "->attributes({$attrs})";
                $after = substr($content, $endPos + 1, 2); $semi=''; if (preg_match('/^\s*;/', $after)) $semi=';';
                $content = substr($content,0,$pos) . $rep . $semi . substr($content,$endPos+1+strlen($semi));
                $offset = $pos + strlen($rep);
            }
        }
        return $content;
    }

    private function convertCheckboxRadio($content, $file)
    {
        foreach (['checkbox','radio'] as $fn) {
            $offset = 0;
            while (($pos = stripos($content, "Form::{$fn}(", $offset)) !== false) {
                $open = strpos($content, '(', $pos);
                $ex = $this->extractParenthesized($content, $open);
                if ($ex === null) { $this->logAmbiguous($file,$pos,substr($content,$pos,80),"unbalanced {$fn}()"); $offset=$pos+6; continue; }
                list($between,$endPos) = $ex;
                $args = $this->splitTopLevelArgs($between);
                $name = $args[0] ?? "''";
                $value = $args[1] ?? "null";
                $checked = $args[2] ?? "null";
                $attrs = $args[3] ?? null;

                if ($fn === 'checkbox') {
                    $checkedExpr = ($checked !== "null") ? $checked : 'false';
                    $valExpr = ($value !== "null") ? $value : "'1'";
                    $rep = "html()->checkbox({$name}, {$checkedExpr}, {$valExpr})";
                } else {
                    $checkedExpr = ($checked !== "null") ? $checked : 'false';
                    $valExpr = ($value !== "null") ? $value : "null";
                    $rep = "html()->radio({$name}, {$checkedExpr}, {$valExpr})";
                }
                if ($attrs !== null) $rep .= "->attributes({$attrs})";
                $after = substr($content, $endPos + 1, 2); $semi=''; if (preg_match('/^\s*;/', $after)) $semi=';';
                $content = substr($content,0,$pos) . $rep . $semi . substr($content,$endPos+1+strlen($semi));
                $offset = $pos + strlen($rep);
            }
        }
        return $content;
    }

    private function convertSelects($content, $file)
    {
        $offset = 0;
        while (($pos = stripos($content, "Form::select(", $offset)) !== false) {
            $open = strpos($content, '(', $pos);
            $ex = $this->extractParenthesized($content, $open);
            if ($ex === null) { $this->logAmbiguous($file,$pos,substr($content,$pos,80),"unbalanced select()"); $offset=$pos+12; continue; }
            list($between,$endPos) = $ex;
            $args = $this->splitTopLevelArgs($between);
            $name = $args[0] ?? "''";
            $options = $args[1] ?? "[]";
            $selected = $args[2] ?? "null";
            $attrs = $args[3] ?? null;

            $childrenCode = null;
            if (preg_match('/^\s*\[/', $options)) {
                $arr = $this->tryEvalArrayLiteral($options);
                if (is_array($arr)) {
                    $parts = [];
                    foreach ($arr as $val => $lab) {
                        $valCode = var_export($val, true);
                        $labCode = var_export($lab, true);
                        $opt = "html()->option({$labCode}, {$valCode})";
                        if ($selected !== "null" && (trim($selected, "'\"") === (string)$val)) {
                            $opt .= "->attributes(['selected' => 'selected'])";
                        }
                        $parts[] = $opt;
                    }
                    $childrenCode = '[' . implode(', ', $parts) . ']';
                } else {
                    $this->logAmbiguous($file,$pos,$options,"complex options array (skipped inline) - using collect() fallback");
                    $childrenCode = "collect({$options})->map(function(\$label,\$value){ return html()->option(\$label, \$value); })->all()";
                }
            } else {
                $childrenCode = "collect({$options})->map(function(\$label,\$value){ return html()->option(\$label, \$value); })->all()";
            }

            $rep = "html()->select({$name})->children({$childrenCode})";
            if ($attrs !== null) $rep .= "->attributes({$attrs})";
            $after = substr($content, $endPos + 1, 2); $semi=''; if (preg_match('/^\s*;/', $after)) $semi=';';
            $content = substr($content,0,$pos) . $rep . $semi . substr($content,$endPos+1+strlen($semi));
            $offset = $pos + strlen($rep);
        }
        return $content;
    }

    private function convertModelToken($content, $file)
    {
        $offset = 0;
        while (($pos = stripos($content, "Form::model(", $offset)) !== false) {
            $open = strpos($content, '(', $pos);
            $ex = $this->extractParenthesized($content, $open);
            if ($ex === null) { $this->logAmbiguous($file,$pos,substr($content,$pos,80),"unbalanced model()"); $offset=$pos+11; continue; }
            list($between,$endPos) = $ex;
            $args = $this->splitTopLevelArgs($between);
            $model = $args[0] ?? '$model';
            $options = $args[1] ?? '[]';
            $formOpen = null;
            if (trim($options) === '[]') $formOpen = "html()->form()->open()";
            else {
                if (preg_match('/^\s*\[/', $options)) {
                    $formOpen = $this->buildFormOpenReplacement($options, $file, $pos);
                    if ($formOpen === null) { $this->logAmbiguous($file,$pos,$options,"complex model() options - skipped form open"); $offset=$endPos+1; continue; }
                } else {
                    $this->logAmbiguous($file,$pos,$options,"dynamic model() options (skipped)");
                    $offset = $endPos + 1;
                    continue;
                }
            }
            $rep = "html()->model({$model}); " . $formOpen;
            $after = substr($content, $endPos + 1, 2); $semi=''; if (preg_match('/^\s*;/', $after)) $semi=';';
            $content = substr($content,0,$pos) . $rep . $semi . substr($content,$endPos+1+strlen($semi));
            $offset = $pos + strlen($rep);
        }

        $content = preg_replace('/\bForm::token\s*\(\s*\)\s*;?/i', 'csrf_field();', $content);

        return $content;
    }

    private function buildAttributesCode($attrs)
    {
        if (empty($attrs)) return '[]';
        $parts = [];
        foreach ($attrs as $k => $v) {
            if (is_string($v)) $parts[] = "'" . addslashes($k) . "' => '" . addslashes($v) . "'";
            elseif (is_bool($v)) $parts[] = "'" . addslashes($k) . "' => " . ($v ? 'true' : 'false');
            elseif (is_null($v)) $parts[] = "'" . addslashes($k) . "' => null";
            else $parts[] = "'" . addslashes($k) . "' => " . var_export($v, true);
        }
        return '[' . implode(', ', $parts) . ']';
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
