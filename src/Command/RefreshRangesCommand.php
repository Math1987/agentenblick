<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Verification\RangeRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:ranges:refresh', description: 'Refresh official IP ranges; changes above 30% remain pending')]
final class RefreshRangesCommand extends Command
{
    public function __construct(private readonly RangeRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->refresher->refresh(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
