<?php

final class Search
{
    public static function tokenize(string $text): array
    {
        $t = mb_strtolower($text, 'UTF-8');
        $tokens = array();

        if (preg_match_all('/[a-z0-9]+/', $t, $m)) {
            foreach ($m[0] as $w) {
                $tokens[] = $w;
            }
        }

        if (preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]+/u', $t, $m2)) {
            foreach ($m2[0] as $seg) {
                $len = mb_strlen($seg, 'UTF-8');
                if ($len === 1) {
                    $tokens[] = $seg;
                    continue;
                }
                for ($i = 0; $i < $len; $i++) {
                    $tokens[] = mb_substr($seg, $i, 1, 'UTF-8');
                }
                for ($i = 0; $i < $len - 1; $i++) {
                    $tokens[] = mb_substr($seg, $i, 2, 'UTF-8');
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    public static function tokenWeight(string $token): float
    {
        if (preg_match('/^[a-z0-9]+$/', $token)) {
            return 1.5;
        }
        if (mb_strlen($token, 'UTF-8') === 2) {
            return 2.0;
        }
        return 1.0;
    }

    public static function textScore(array $queryTokens, array $entryTokens): float
    {
        if (!$entryTokens) {
            return 0.0;
        }
        $set = array_flip($entryTokens);
        $sum = 0.0;
        foreach ($queryTokens as $token) {
            if (isset($set[$token])) {
                $sum += self::tokenWeight($token);
            }
        }
        return $sum / log(2 + count($entryTokens));
    }

    public static function cosine(?array $a, ?array $b): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (count($a) !== count($b) || count($a) === 0) {
            return null;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $k => $v) {
            $dot += $v * $b[$k];
            $na += $v * $v;
            $nb += $b[$k] * $b[$k];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return null;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}