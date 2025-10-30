<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class InputConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
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
}
