<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Benchmark\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:benchmark', description: 'Inspect the exact weekly benchmark or manage explicit participation')]
final class BenchmarkCommand extends Command
{
    public function __construct(private readonly Manager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status, preview, history, enable, disable, send, run or comparison', 'status');
        $this->addOption('period', null, InputOption::VALUE_REQUIRED, 'Completed ISO week for preview');
        $this->addOption('accept-consent', null, InputOption::VALUE_REQUIRED, 'Consent version 1; required to enable');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Consent language de-DE or en-GB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        if ($action === 'enable' && ($input->getOption('accept-consent') !== '1' || !in_array($input->getOption('locale'), ['de-DE', 'en-GB'], true))) {
            throw new \InvalidArgumentException('Read the README consent notice and pass --accept-consent=1 --locale=de-DE or en-GB.');
        }
        $period = $input->getOption('period');
        $result = match ($action) {
            'status' => $this->manager->status(),
            'preview' => $this->manager->preview(is_string($period) ? $period : null),
            'history' => $this->manager->history(),
            'enable' => $this->manager->enable((string) $input->getOption('locale')),
            'disable' => $this->manager->disable(),
            'send' => $this->manager->send(),
            'run' => $this->manager->run(),
            'comparison' => $this->manager->comparison(),
            default => throw new \InvalidArgumentException('Unknown benchmark action.'),
        };
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
