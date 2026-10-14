<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Heuristics\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:heuristics', description: 'Read or explicitly enable the uncalibrated experimental detector.')]
final class HeuristicsCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status, enable or disable', 'status')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Integer from 30 to 100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current = $this->settings->read();
        $threshold = $input->getOption('threshold');
        $threshold = $threshold === null ? $current['threshold'] : filter_var($threshold, FILTER_VALIDATE_INT);
        if (!is_int($threshold) || $threshold < 30 || $threshold > 100) {
            throw new \InvalidArgumentException('Threshold must be an integer from 30 to 100.');
        }
        $result = match ($input->getArgument('action')) {
            'status' => $current,
            'enable' => $this->settings->save(true, $threshold),
            'disable' => $this->settings->save(false, $threshold),
            default => throw new \InvalidArgumentException('Expected status, enable or disable.'),
        };
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
