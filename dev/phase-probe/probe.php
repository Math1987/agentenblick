<?php

declare(strict_types=1);

/** Disposable bench only: no headers, URL, body, cookies or identity are logged. */
final class AgentenblickPhaseProbe
{
    private static bool $ready = false;
    private static bool $enabled = false;
    private static array $row = [];
    private static array $depth = ['pre' => 0, 'post' => 0];
    private static array $started = ['pre' => 0, 'post' => 0];

    public static function initialize(): void
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        if (PHP_SAPI === 'cli' || ($_SERVER['HTTP_X_AGENTENBLICK_PHASE_RUN'] ?? '') !== '__RUN__'
            || !preg_match('/^([0-3]):([0-2]):([0-3]):([0-9]{1,6})$/D', $_SERVER['HTTP_X_AGENTENBLICK_PHASE_SAMPLE'] ?? '', $match)) {
            return;
        }
        self::$enabled = true;
        self::$row = ['stage' => (int) $match[1], 'temperature' => (int) $match[2], 'population' => (int) $match[3], 'sample' => (int) $match[4], 'pre_ns' => 0, 'post_ns' => 0, 'pre_calls' => 0, 'post_calls' => 0, 'php_version_id' => PHP_VERSION_ID, 'sapi_code' => match (PHP_SAPI) { 'apache2handler' => 1, 'fpm-fcgi' => 2, 'cgi-fcgi' => 3, default => 0 }];
        register_shutdown_function(static function (): void {
            // JSONL append is deliberately outside every timed section.
            self::$row['open_timers'] = self::$depth['pre'] + self::$depth['post'];
            file_put_contents('__OUTPUT__', json_encode(self::$row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        });
    }

    public static function enter(string $phase): void
    {
        if (!self::$enabled) {
            return;
        }
        ++self::$row[$phase . '_calls'];
        if (self::$depth[$phase]++ === 0) {
            self::$started[$phase] = hrtime(true);
        }
    }

    public static function leave(string $phase): void
    {
        $now = hrtime(true);
        if (self::$enabled && --self::$depth[$phase] === 0) {
            self::$row[$phase . '_ns'] += $now - self::$started[$phase];
        }
    }
}

AgentenblickPhaseProbe::initialize();
