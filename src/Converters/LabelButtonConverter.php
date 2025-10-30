<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class LabelButtonConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
    {
        // label(name, text = null, attrs = [])
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

        // submit / button
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
}
