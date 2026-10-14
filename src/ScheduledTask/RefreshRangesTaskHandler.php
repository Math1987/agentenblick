<?php declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Aithos\Agentenblick\Verification\RangeRefresher;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: RefreshRangesTask::class)]
final class RefreshRangesTaskHandler extends ScheduledTaskHandler
{
    public function __construct(EntityRepository $scheduledTaskRepository, LoggerInterface $logger, private readonly RangeRefresher $worker)
    {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->worker->refresh();
    }
}
