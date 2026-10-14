<?php declare(strict_types=1);

// Short-lived CLI subprocess. The caller imposes a one-second wall-clock timeout.
// The IP travels only over stdin, never through argv, files or log messages.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$input = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$ip = $input['ip'] ?? '';
$suffixes = $input['suffixes'] ?? [];
if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false || !is_array($suffixes)) {
    exit(1);
}
$hostname = @gethostbyaddr($ip);
$allowed = false;
if (is_string($hostname) && $hostname !== $ip) {
    $hostname = strtolower(rtrim($hostname, '.'));
    foreach ($suffixes as $suffix) {
        if (is_string($suffix) && str_starts_with($suffix, '.') && str_ends_with($hostname, $suffix)) {
            $allowed = true;
        }
    }
}
$verified = false;
if ($allowed) {
    foreach (@dns_get_record($hostname, DNS_A | DNS_AAAA) ?: [] as $record) {
        $forward = $record['ip'] ?? $record['ipv6'] ?? '';
        if (is_string($forward) && @inet_pton($forward) === inet_pton($ip)) {
            $verified = true;
        }
    }
}
echo $verified ? '1' : '0';
