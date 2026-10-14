<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Admin\AnalyticsReader;
use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agentenblick:report', description: 'Report locally observed agent traffic')]
final class ReportCommand extends Command
{
    public function __construct(private readonly AnalyticsReader $reader, private readonly ProxyDiagnostics $proxyDiagnostics)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Calendar days including today (UTC)', '7');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable report');
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Sales channel ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 400]]);
        if ($days === false) {
            throw new \InvalidArgumentException('--days must be between 1 and 400');
        }
        $since = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $channel = $input->getOption('sales-channel');
        $filter = new ReportFilter($days, is_string($channel) ? $channel : null);
        $rows = $this->reader->groups($filter);
        $totals = AnalyticsReader::totals($rows);
        $sessions = $totals['sessions'];
        $nonHumanSessions = $totals['agentSessions'];
        $report = [
            'since' => $since, 'timezone' => 'UTC', 'sessions' => $sessions,
            'observed_agent_sessions' => $nonHumanSessions,
            'observed_agent_sessions_pct' => $sessions > 0 ? round(100 * $nonHumanSessions / $sessions, 1) : null,
            'scope' => 'PHP-visible main requests; identified crawler/agent traffic, not all AI usage',
            'agents' => $rows,
            'human_browsers' => $this->reader->humanBrowsers($filter),
            'proxy_diagnostics' => $this->proxyDiagnostics->report(),
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('Agentenblick — observed traffic');
            $io->text(sprintf('%d sessions; %d identified/declared agent sessions (%s%%)', $sessions, $nonHumanSessions, $report['observed_agent_sessions_pct'] ?? 'n/a'));
            $io->table(['Operator', 'Agent', 'Kind', 'Evidence', 'Requests', 'Sessions'], array_map('array_values', $rows));
            $io->note($report['scope']);
            if ($report['proxy_diagnostics']['warning']) {
                $io->warning($report['proxy_diagnostics']['message']);
                $io->text($report['proxy_diagnostics']['remediation']);
            }
        }
        return self::SUCCESS;
    }
}
