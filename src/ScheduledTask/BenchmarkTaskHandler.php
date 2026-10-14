<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\ScheduledTask;

use Aithos\Agentenblick\Benchmark\Manager;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: BenchmarkTask::class)]
final class BenchmarkTaskHandler extends ScheduledTaskHandler
{
    public function __construct(EntityRepository $scheduledTaskRepository, LoggerInterface $logger, private readonly Manager $manager)
    {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->manager->run();
    }
}
