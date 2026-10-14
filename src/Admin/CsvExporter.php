<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Admin;

final class CsvExporter
{
    /** @param list<array<string, mixed>> $items */
    public static function contents(array $items): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create CSV.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['operator', 'agent', 'kind', 'evidence', 'requests', 'sessions', 'last_seen_utc', 'evidence_breakdown', 'top_routes'], ',', '"', '', "\r\n");
            foreach ($items as $item) {
                $values = [
                    $item['operator'], $item['agent'], $item['kind'], $item['evidence'],
                    (string) $item['requests'], (string) $item['sessions'], $item['lastSeen'] ?? '',
                    json_encode($item['byEvidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    json_encode($item['topRoutes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
                fputcsv($stream, array_map(self::cell(...), $values), ',', '"', '', "\r\n");
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read CSV.');
            }
            return $contents;
        } finally {
            fclose($stream);
        }
    }

    private static function cell(string $value): string
    {
        $value = str_replace("\0", '', $value);
        return preg_match('/^[\p{Z}\s\p{Cc}]*[=+\-@]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1 ? "'" . $value : $value;
    }
}
