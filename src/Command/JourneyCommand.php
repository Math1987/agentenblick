<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Journey\JourneyReader;
use Aithos\Agentenblick\Journey\SessionCloser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:journey', description: 'Read minimised agent journeys or close inactive sessions.')]
final class JourneyCommand extends Command
{
    public function __construct(private readonly JourneyReader $reader, private readonly SessionCloser $closer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'summary, sessions or close', 'summary')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'UTC days', '7')
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Shopware sales channel ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('sales-channel');
        $filter = new ReportFilter((int) $input->getOption('days'), is_string($channel) ? $channel : null);
        $result = match ($input->getArgument('action')) {
            'close' => $this->closer->run(),
            'summary' => $this->reader->summary($filter),
            'sessions' => $this->reader->sessions($filter),
            default => throw new \InvalidArgumentException('Expected summary, sessions or close.'),
        };
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
