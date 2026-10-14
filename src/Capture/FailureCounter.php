<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Psr\Log\LoggerInterface;

final class FailureCounter
{
    public function __construct(private readonly string $projectDir, private readonly LoggerInterface $logger)
    {
    }

    public function record(\Throwable $error): void
    {
        // Never log exception messages, SQL, request input, tokens or stack arguments.
        try {
            $directory = $this->projectDir . '/var/agentenblick';
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return;
            }
            $handle = @fopen($directory . '/errors.json', 'c+');
            if ($handle === false) {
                return;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    return;
                }
                $data = json_decode(stream_get_contents($handle) ?: '{}', true);
                $data = is_array($data) ? $data : [];
                $type = get_class($error);
                $entry = $data[$type] ?? ['count' => 0, 'last_logged' => 0];
                ++$entry['count'];
                if (time() - $entry['last_logged'] >= 60) {
                    $entry['last_logged'] = time();
                    $this->logger->warning('Agentenblick observation failed', ['type' => $type]);
                }
                $data[$type] = $entry;
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            // This failure path must itself remain fail-open.
        }
    }
}
