<?php declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class RefreshRangesTask extends ScheduledTask
{
    public static function getTaskName(): string { return 'agentenblick.refresh_ranges'; }
    public static function getDefaultInterval(): int { return 21600; }
    public static function shouldRescheduleOnFailure(): bool { return true; }
}
