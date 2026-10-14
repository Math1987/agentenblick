<?php

declare(strict_types=1);

// Token positions, not regex/brace counting through strings or comments.
$run = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{16}$/D', $run)) {
    throw new RuntimeException('Invalid disposable run id.');
}
$directory = '/tmp/agentenblick-phase-probe-' . $run;
$helper = $directory . '/probe.php';
$root = '/var/www/html/custom/plugins/AithosAgentenblick/';
$targets = [
    '/var/www/html/public/index.php' => [],
    $root . 'src/Capture/ObservationSubscriber.php' => ['begin' => 'pre', 'onRequest' => 'pre', 'complete' => 'pre', 'onResponse' => 'pre', 'offersSessionCookie' => 'pre', 'onTerminate' => 'post', 'flush' => 'post'],
    $root . 'src/Journey/EventSubscriber.php' => array_fill_keys(['onSearch', 'onSuggest', 'onProduct', 'recordProduct', 'recordProductResponse', 'onCart', 'onCheckout', 'onFinish', 'onOrder', 'onException', 'observe'], 'pre'),
];
foreach ($targets as $path => $methods) {
    $original = file_get_contents($path);
    if (!is_string($original) || str_contains($original, 'AgentenblickPhaseProbe')) {
        throw new RuntimeException('Missing or already instrumented source.');
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
    $edits = [[$insertAt, "\nrequire_once '" . $helper . "';\n"]];
    $found = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            ++$j;
        }
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || !isset($methods[$tokens[$j][1]])) {
            continue;
        }
        $name = $tokens[$j][1];
        for (; isset($tokens[$j]) && $tokens[$j] !== '{'; ++$j) {}
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
        $phase = $methods[$name];
        $edits[] = [$start, "\n\\AgentenblickPhaseProbe::enter('" . $phase . "'); try {\n"];
        $edits[] = [$offsets[$j], "\n} finally { \\AgentenblickPhaseProbe::leave('" . $phase . "'); }\n"];
        $found[$name] = true;
    }
    if (count($found) !== count($methods)) {
        throw new RuntimeException('Expected methods not found.');
    }
    usort($edits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
    $code = $original;
    foreach ($edits as [$position, $text]) {
        $code = substr_replace($code, $text, $position, 0);
    }
    $temporary = $directory . '/' . hash('sha256', $path) . '.php';
    file_put_contents($temporary, $code);
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Instrumented PHP failed lint; original file not replaced.');
    }
    chmod($temporary, fileperms($path) & 0777);
    if (!rename($temporary, $path)) {
        throw new RuntimeException('Could not install validated disposable instrumentation.');
    }
    echo json_encode(['path' => $path, 'original_sha256' => hash('sha256', $original), 'instrumented_sha256' => hash('sha256', $code), 'methods' => count($found)], JSON_THROW_ON_ERROR) . "\n";
}
