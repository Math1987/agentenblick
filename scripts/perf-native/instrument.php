<?php

declare(strict_types=1);

// Prepare and lint disposable copies. The Python runner owns backups/restoration.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$input = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
foreach ($input['targets'] as $target) {
    $original = file_get_contents($target['path']);
    if (!is_string($original) || str_contains($original, 'AgentenblickNativeProbe')) {
        throw new RuntimeException('Missing or previously instrumented source.');
    }
    $tokens = token_get_all($original, TOKEN_PARSE);
    $offsets = [];
    $offset = 0;
    foreach ($tokens as $index => $token) {
        $offsets[$index] = $offset;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }
    $insertAt = null;
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_OPEN_TAG && $insertAt === null) {
            $insertAt = $offsets[$i] + strlen($token[1]);
        }
        if (is_array($token) && in_array($token[0], [T_DECLARE, T_NAMESPACE], true)) {
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; ++$j) {
                if ($tokens[$j] === '{') {
                    throw new RuntimeException('Bracketed declaration is unsupported.');
                }
            }
            $insertAt = $offsets[$j] + 1;
        }
        if (is_array($token) && in_array($token[0], [T_USE, T_CLASS, T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_VARIABLE], true)) {
            break;
        }
    }
    if ($insertAt === null) {
        throw new RuntimeException('Missing PHP opening tag.');
    }
    $edits = [[$insertAt, "\nrequire_once " . var_export($input['helper'], true) . "; // AgentenblickNativeProbe\n"]];
    $found = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            ++$j;
        }
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || !isset($target['methods'][$tokens[$j][1]])) {
            continue;
        }
        $name = $tokens[$j][1];
        for (; isset($tokens[$j]) && $tokens[$j] !== '{'; ++$j) {
        }
        $start = $offsets[$j] + 1;
        $depth = 1;
        for (++$j; isset($tokens[$j]); ++$j) {
            $part = $tokens[$j];
            if ($part === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($part === '}' && --$depth === 0) {
                break;
            }
        }
        if ($depth !== 0 || isset($found[$name])) {
            throw new RuntimeException('Method body is ambiguous.');
        }
        $label = var_export($target['methods'][$name], true);
        $edits[] = [$start, "\n\\AgentenblickNativeProbe::enter(" . $label . "); try {\n"];
        $edits[] = [$offsets[$j], "\n} finally { \\AgentenblickNativeProbe::leave(" . $label . "); }\n"];
        $found[$name] = true;
    }
    if (count($found) !== count($target['methods'])) {
        throw new RuntimeException('Expected methods were not found.');
    }
    usort($edits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
    $code = $original;
    foreach ($edits as [$position, $text]) {
        $code = substr_replace($code, $text, $position, 0);
    }
    file_put_contents($target['prepared'], $code);
}
