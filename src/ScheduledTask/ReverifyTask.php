<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class ReverifyTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'agentenblick.reverify';
    }
    public static function getDefaultInterval(): int
    {
        return 300;
    }
    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
