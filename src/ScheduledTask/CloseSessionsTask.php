<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class CloseSessionsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'agentenblick.close_sessions';
    }

    public static function getDefaultInterval(): int
    {
        return 900;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
