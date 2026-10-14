<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Health\HealthReader;
use Aithos\Agentenblick\Health\ProbeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:health', description: 'Read saved health results; --probe=<domain-id> explicitly calls the configured public HTTPS shop.')]
final class HealthCommand extends Command
{
    public function __construct(private readonly HealthReader $reader, private readonly ProbeService $probes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Configured domain ID for saved results')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, '7 or 30 days', '7')
            ->addOption('probe', null, InputOption::VALUE_REQUIRED, 'Explicitly probe this configured domain ID')
            ->addOption('auto-test', null, InputOption::VALUE_NONE, 'Also request the homepage with GPTBot, ClaudeBot and ChatGPT-User; source IP is this server, not the operators');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);
        if (!in_array($days, [7, 30], true)) {
            throw new \InvalidArgumentException('Use days=7 or 30.');
        }
        $probe = $input->getOption('probe');
        $domain = $input->getOption('domain');
        foreach ([$probe, $domain] as $id) {
            if ($id !== null && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id))) {
                throw new \InvalidArgumentException('Expected configured domain IDs, never a URL.');
            }
        }
        if ($input->getOption('auto-test') && $probe === null) {
            throw new \InvalidArgumentException('Use --probe before requesting the optional auto-test.');
        }
        if (is_string($probe)) {
            $this->probes->run($probe, (bool) $input->getOption('auto-test'));
            $domain = $probe;
        }
        $output->writeln(json_encode($this->reader->read(new ReportFilter($days), is_string($domain) ? $domain : null), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
