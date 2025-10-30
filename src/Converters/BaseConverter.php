<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

abstract class BaseConverter
{
    protected $ambiguousLog = [];

    abstract public function convert(string $content, string $file): string;

    protected function logAmbiguous(string $file, int $lineOrOffset, string $snippet, string $reason): void
    {
        $this->ambiguousLog[] = ['file' => $file, 'pos' => $lineOrOffset, 'snippet' => substr($snippet, 0, 200), 'reason' => $reason];
    }

    public function getAmbiguousLogs(): array
    {
        return $this->ambiguousLog;
    }

    protected function extractParenthesized(string $content, int $startParen): ?array
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

    protected function splitTopLevelArgs(string $s): array
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

    protected function tryEvalArrayLiteral(string $s): ?array
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
    
    protected function buildAttributesCode(array $attrs): string
    {
        if (empty($attrs)) return '[]';
        $parts = [];
        foreach ($attrs as $k => $v) {
            if (is_string($v)) $parts[] = "'".addslashes($k)."' => '".addslashes($v)."'";
            elseif (is_bool($v)) $parts[] = "'".addslashes($k)."' => ".($v ? 'true' : 'false');
            elseif (is_null($v)) $parts[] = "'".addslashes($k)."' => null";
            else $parts[] = "'".addslashes($k)."' => ".var_export($v, true);
        }
        return '[' . implode(', ', $parts) . ']';
    }
}