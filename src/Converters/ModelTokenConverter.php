<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class ModelTokenConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
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

    private function buildFormOpenReplacement(string $argString, string $file, int $pos): ?string
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
                    if (is_string($parsed['route'])) $actionExpr = "route('".addslashes($parsed['route'])."')";
                    elseif (is_array($parsed['route']) && isset($parsed['route'][0]) && is_string($parsed['route'][0])) {
                        $name = array_shift($parsed['route']);
                        $argCodes = [];
                        foreach ($parsed['route'] as $a) $argCodes[] = var_export($a, true);
                        $actionExpr = "route('".addslashes($name)."'" . (empty($argCodes) ? '' : (", [" . implode(', ', $argCodes) . "]")) . ")";
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
}
