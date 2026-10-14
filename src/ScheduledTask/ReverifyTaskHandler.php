<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Aithos\Agentenblick\Verification\PendingSignatures;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ReverifyTask::class)]
final class ReverifyTaskHandler extends ScheduledTaskHandler
{
    public function __construct(EntityRepository $scheduledTaskRepository, LoggerInterface $logger, private readonly PendingSignatures $worker)
    {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->worker->run();
    }
}
