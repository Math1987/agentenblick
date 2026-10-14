<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Admin;

use Aithos\Agentenblick\Verification\CatalogueProvider;
use Aithos\Agentenblick\Verification\RangeRefresher;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RangeApprovals
{
    public function __construct(private readonly Connection $connection, private readonly CatalogueProvider $catalogues)
    {
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        $sources = [];
        foreach ($this->catalogues->load()->agents() as $agent) {
            $sources[$agent['id']] = $agent['rangesUrl'] ?? null;
        }
        $items = [];
        foreach ($this->connection->fetchAllAssociative('SELECT agent, ranges_json, pending_json, updated_at FROM agentenblick_ip_ranges WHERE pending_json IS NOT NULL ORDER BY agent LIMIT 500') as $row) {
            $current = json_decode($row['ranges_json'], true, 32, JSON_THROW_ON_ERROR);
            $pending = json_decode($row['pending_json'], true, 32, JSON_THROW_ON_ERROR);
            $added = array_values(array_diff($pending, $current));
            $removed = array_values(array_diff($current, $pending));
            $items[] = [
                'agent' => $row['agent'], 'currentCount' => count($current), 'pendingCount' => count($pending),
                'updatedAt' => str_replace(' ', 'T', $row['updated_at']) . 'Z', 'version' => self::version($row),
                'addedRanges' => array_slice($added, 0, 50), 'removedRanges' => array_slice($removed, 0, 50),
                'addedCount' => count($added), 'removedCount' => count($removed),
                'changesTruncated' => count($added) > 50 || count($removed) > 50, 'sourceUrl' => $sources[$row['agent']] ?? null,
            ];
        }
        return $items;
    }

    public function approve(string $agent, string $version): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $agent) || !preg_match('/^[a-f0-9]{64}$/D', $version)) {
            throw new BadRequestHttpException('A valid agent and reviewed version are required.');
        }
        $this->connection->transactional(function (Connection $connection) use ($agent, $version): void {
            $row = $connection->fetchAssociative('SELECT agent, ranges_json, pending_json, updated_at FROM agentenblick_ip_ranges WHERE agent = ? FOR UPDATE', [$agent]);
            if ($row === false || $row['pending_json'] === null) {
                throw new NotFoundHttpException('No pending ranges for this agent.');
            }
            if (!hash_equals(self::version($row), $version)) {
                throw new ConflictHttpException('The proposed ranges changed. Reload and review them again.');
            }
            try {
                $proposed = json_decode($row['pending_json'], true, 32, JSON_THROW_ON_ERROR);
                $ranges = RangeRefresher::extract($proposed);
            } catch (\JsonException|\UnexpectedValueException $error) {
                throw new ConflictHttpException('The proposed ranges are invalid; refresh the catalogue.', $error);
            }
            if (!is_array($proposed) || !array_is_list($proposed) || $ranges === [] || count($ranges) > 50000 || count($ranges) !== count($proposed)) {
                throw new ConflictHttpException('The proposed ranges are invalid; refresh the catalogue.');
            }
            $connection->update('agentenblick_ip_ranges', ['ranges_json' => json_encode($ranges, JSON_THROW_ON_ERROR), 'pending_json' => null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['agent' => $agent]);
        });
    }

    /** @param array<string, mixed> $row */
    private static function version(array $row): string
    {
        return hash('sha256', $row['agent'] . "\0" . $row['ranges_json'] . "\0" . $row['pending_json'] . "\0" . $row['updated_at']);
    }
}
