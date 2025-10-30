<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class CheckboxRadioConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
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
}
