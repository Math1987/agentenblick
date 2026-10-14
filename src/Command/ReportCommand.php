<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agentenblick:report', description: 'Report locally observed agent traffic')]
final class ReportCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly ProxyDiagnostics $proxyDiagnostics)
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
        $parameters = [$since];
        $where = 'day >= ?';
        if ($input->getOption('sales-channel') !== null) {
            $where .= ' AND sales_channel_id = ?';
            $parameters[] = $input->getOption('sales-channel');
        }
        $rows = $this->connection->fetchAllAssociative('SELECT operator, agent, kind, evidence, SUM(requests) AS requests, SUM(sessions) AS sessions FROM agentenblick_daily WHERE ' . $where . ' GROUP BY operator, agent, kind, evidence ORDER BY requests DESC', $parameters);
        $sessions = 0;
        $nonHumanSessions = 0;
        foreach ($rows as &$row) {
            $row['sessions'] = (int) $row['sessions'];
            $row['requests'] = (int) $row['requests'];
            $sessions += $row['sessions'];
            if ($row['kind'] !== 'human_or_unknown') {
                $nonHumanSessions += $row['sessions'];
            }
        }
        unset($row);
        $report = [
            'since' => $since, 'timezone' => 'UTC', 'sessions' => $sessions,
            'observed_agent_sessions' => $nonHumanSessions,
            'observed_agent_sessions_pct' => $sessions > 0 ? round(100 * $nonHumanSessions / $sessions, 1) : null,
            'scope' => 'PHP-visible main requests; identified crawler/agent traffic, not all AI usage',
            'agents' => $rows,
            'human_browsers' => array_map(static fn (array $browser): array => ['browser_family' => $browser['browser_family'], 'requests' => (int) $browser['requests'], 'sessions' => (int) $browser['sessions']], $this->connection->fetchAllAssociative("SELECT browser_family, SUM(requests) AS requests, SUM(sessions) AS sessions FROM agentenblick_daily WHERE " . $where . " AND kind = 'human_or_unknown' GROUP BY browser_family", $parameters)),
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
