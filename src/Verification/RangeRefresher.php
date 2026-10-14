<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class RangeRefresher
{
    public function __construct(private readonly Connection $connection, private readonly CatalogueProvider $catalogues, private readonly SystemConfigService $config)
    {
    }

    /** @return array<string, string> */
    public function refresh(): array
    {
        if ($this->config->getBool('AithosAgentenblick.config.offline')) {
            return ['status' => 'offline; no outbound requests'];
        }
        $result = [];
        foreach ($this->catalogues->load()->agents() as $agent) {
            if (!is_string($agent['rangesUrl'] ?? null)) {
                continue;
            }
            try {
                $ranges = self::extract((new SafeFetcher())->json($agent['rangesUrl'], 1048576));
                if (count($ranges) < 1 || count($ranges) > 50000) {
                    throw new \UnexpectedValueException('implausible_range_count');
                }
                $pending = $this->connection->transactional(function (Connection $connection) use ($agent, $ranges): bool {
                    // Serialize the local decision with an administrator's approval;
                    // the network download has already completed outside this lock.
                    $row = $connection->fetchAssociative('SELECT ranges_json FROM agentenblick_ip_ranges WHERE agent = ? FOR UPDATE', [$agent['id']]);
                    $previous = $row === false ? ($agent['ranges'] ?? []) : json_decode($row['ranges_json'], true, 32, JSON_THROW_ON_ERROR);
                    $pending = self::needsConfirmation($previous, $ranges);
                    $connection->executeStatement('INSERT INTO agentenblick_ip_ranges (agent, ranges_json, pending_json, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ranges_json = VALUES(ranges_json), pending_json = VALUES(pending_json), updated_at = VALUES(updated_at)', [
                        $agent['id'], json_encode($pending ? $previous : $ranges, JSON_THROW_ON_ERROR),
                        $pending ? json_encode($ranges, JSON_THROW_ON_ERROR) : null, gmdate('Y-m-d H:i:s'),
                    ]);
                    return $pending;
                });
                $result[$agent['id']] = $pending ? 'pending_confirmation' : 'updated:' . count($ranges);
            } catch (\Throwable $error) {
                $result[$agent['id']] = 'kept_last_valid:' . get_class($error);
            }
        }
        return $result;
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     */
    public static function needsConfirmation(array $old, array $new): bool
    {
        return $old !== [] && (count(array_diff($old, $new)) + count(array_diff($new, $old))) / count($old) > 0.30;
    }

    /** @return list<string> */
    public static function extract(mixed $value): array
    {
        $ranges = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $ranges = array_merge($ranges, self::extract($item));
            }
        } elseif (is_string($value) && preg_match('~^([0-9a-fA-F:.]+)/([0-9]{1,3})$~D', $value, $matches)) {
            $ip = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            $maximum = str_contains($matches[1], ':') ? 128 : 32;
            if ($ip === false || (int) $matches[2] < 1 || (int) $matches[2] > $maximum) {
                throw new \UnexpectedValueException('invalid_public_cidr');
            }
            $ranges[] = $value;
        }
        $ranges = array_values(array_unique($ranges));
        sort($ranges);
        return $ranges;
    }
}
