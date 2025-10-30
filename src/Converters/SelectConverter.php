<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class SelectConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
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
}
