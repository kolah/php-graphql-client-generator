<?php

namespace Kolah\GraphQL\Generator;

final class Util
{
    public static function fromCamelCaseToUnderscore(string $str): string
    {
        $str[0] = strtolower($str[0]);
        $func = function ($c) {
            return "_" . strtolower($c[1]);
        };
        return preg_replace_callback('/([A-Z])/', $func, $str);
    }

    public static function replaceTokens(string $template, array $values): string
    {
        $tokens = array_keys($values);
        $tokens = array_map(function ($key) {
            return sprintf('{%s}', $key);
        }, $tokens);

        return str_replace($tokens, $values, $template);
    }
}
