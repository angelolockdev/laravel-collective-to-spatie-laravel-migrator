<?php

namespace Fddo\LaravelHtmlMigrator\Converters;

class OpenCloseConverter extends BaseConverter
{
    public function convert(string $content, string $file): string
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
