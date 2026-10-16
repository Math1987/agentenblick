<?php

declare(strict_types=1);

/** Temporary native-bench instrumentation: numeric records only, no request data. */
final class AgentenblickNativeProbe
{
    private static bool $enabled = false;
    private static array $row = [];
    private static array $depth = [];
    private static array $started = [];
    private static bool $kernelDelayApplied = false;

    public static function initialize(): void
    {
        if (PHP_SAPI === 'cli' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_AGENTENBLICK_NATIVE_RUN'] ?? '')
            || !preg_match('/^([0-3]):([0-2]):([0-1]):([0-9]{1,6})$/D', $_SERVER['HTTP_X_AGENTENBLICK_NATIVE_SAMPLE'] ?? '', $match)) {
            return;
        }
        self::$enabled = true;
        self::$row = ['stage' => (int) $match[1], 'temperature' => (int) $match[2], 'population' => (int) $match[3], 'sample' => (int) $match[4], 'php_version_id' => PHP_VERSION_ID, 'fpm' => (int) (PHP_SAPI === 'fpm-fcgi')];
        self::$row['kernel_delay_ns'] = 0;
        foreach (['pre', 'post', 'daily_key', 'classifier', 'store', 'health_prepare', 'health_record', 'proxy', 'catalogue'] as $label) {
            self::$row[$label . '_ns'] = 0;
            self::$row[$label . '_calls'] = 0;
            self::$depth[$label] = 0;
        }
        register_shutdown_function(static function (): void {
            self::$row['open_timers'] = array_sum(self::$depth);
            file_put_contents('__OUTPUT__', json_encode(self::$row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        });
        // A neutral FPM/proxy flush check, not a Shopware kernel.terminate proof.
        if ('__PHASE__' === 'terminate' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '') === '/_agentenblick-fpm-__PATH__') {
            $delay = ($_SERVER['HTTP_X_AGENTENBLICK_NATIVE_DELAY'] ?? '') === '2000' ? 2000 : 0;
            $supported = PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request');
            $body = json_encode(['sample' => self::$row['sample'], 'delayMs' => $delay, 'fpm' => $supported], JSON_THROW_ON_ERROR);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($body));
            echo $body;
            if ($supported) {
                fastcgi_finish_request();
            }
            $started = hrtime(true);
            usleep($delay * 1000);
            self::$row['flush_delay_ns'] = hrtime(true) - $started;
            exit;
        }
    }

    public static function enter(string $label): void
    {
        if (!self::$enabled) {
            return;
        }
        ++self::$row[$label . '_calls'];
        if (self::$depth[$label]++ === 0) {
            self::$started[$label] = hrtime(true);
        }
        // Only the actual onTerminate method is instrumented as post in this phase.
        // initialize() already required the exact capability AND loopback peer.
        if ('__PHASE__' === 'kernel-terminate' && $label === 'post' && !self::$kernelDelayApplied
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '') === '/') {
            self::$kernelDelayApplied = true;
            if (($_SERVER['HTTP_X_AGENTENBLICK_NATIVE_DELAY'] ?? '') === '2000') {
                $started = hrtime(true);
                usleep(2000000);
                self::$row['kernel_delay_ns'] = hrtime(true) - $started;
            }
        }
    }

    public static function leave(string $label): void
    {
        $now = hrtime(true);
        if (self::$enabled && --self::$depth[$label] === 0) {
            self::$row[$label . '_ns'] += $now - self::$started[$label];
        }
    }
}

AgentenblickNativeProbe::initialize();
