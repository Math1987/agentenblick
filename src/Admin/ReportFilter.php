<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class ReportFilter
{
    public function __construct(public int $days = 7, public ?string $salesChannelId = null, public int $page = 1, public int $limit = 25)
    {
        if ($days < 1 || $days > 400 || $page < 1 || $page > 1000 || $limit < 1 || $limit > 100
            || ($salesChannelId !== null && !preg_match('/^[a-f0-9]{32}$/D', $salesChannelId))) {
            throw new BadRequestHttpException('Invalid analytics filters.');
        }
    }

    public static function fromRequest(Request $request): self
    {
        $days = filter_var($request->query->get('days', '7'), FILTER_VALIDATE_INT);
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        $limit = filter_var($request->query->get('limit', '25'), FILTER_VALIDATE_INT);
        $channel = $request->query->all()['salesChannelId'] ?? null;
        if (!in_array($days, [7, 30], true) || !is_int($page) || !is_int($limit) || ($channel !== null && !is_string($channel))) {
            throw new BadRequestHttpException('Use days=7 or 30, a valid salesChannelId, and integer pagination.');
        }
        return new self($days, $channel === '' ? null : $channel, $page, $limit);
    }

    /** @return array{since: string, until: string, days: int, salesChannelId: ?string, timezone: string} */
    public function period(): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        return ['since' => $today->modify('-' . ($this->days - 1) . ' days')->format('Y-m-d'), 'until' => $today->format('Y-m-d'), 'days' => $this->days, 'salesChannelId' => $this->salesChannelId, 'timezone' => 'UTC'];
    }

    /** @return array{string, list<string>} */
    public function where(string $column = 'day'): array
    {
        if (!in_array($column, ['day', 'observed_at'], true)) {
            throw new \InvalidArgumentException('Invalid analytics date column.');
        }
        $period = $this->period();
        $exclusiveEnd = (new \DateTimeImmutable($period['until'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $where = $column . ' >= ? AND ' . $column . ' < ?';
        $parameters = [$period['since'], $exclusiveEnd];
        if ($this->salesChannelId !== null) {
            $where .= ' AND sales_channel_id = ?';
            $parameters[] = $this->salesChannelId;
        }
        return [$where, $parameters];
    }
}
