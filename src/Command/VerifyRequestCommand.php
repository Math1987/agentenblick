<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Verification\CatalogueProvider;
use Aithos\Agentenblick\Verification\Classifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

#[AsCommand(name: 'agentenblick:verify-request', description: 'Verify the headers of an HTTP request fixture without recording a visit')]
final class VerifyRequestCommand extends Command
{
    public function __construct(private readonly Classifier $classifier, private readonly CatalogueProvider $catalogues)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'HTTP request file (only its headers are read)');
        $this->addOption('client-ip', null, InputOption::VALUE_REQUIRED, 'Explicit test source address', '127.0.0.1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $handle = fopen((string) $input->getArgument('file'), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open HTTP fixture');
        }
        try {
            $first = fgets($handle, 8192);
            if (!is_string($first) || !preg_match('~^([A-Z]+) (\S+) HTTP/[12](?:\.[01])?\r?\n$~', $first, $line)) {
                throw new \UnexpectedValueException('Invalid HTTP request line');
            }
            $headers = [];
            $bytes = strlen($first);
            while (($header = fgets($handle, 16385)) !== false && trim($header) !== '') {
                $bytes += strlen($header);
                if ($bytes > 65536 || !str_contains($header, ':')) {
                    throw new \UnexpectedValueException('Invalid or oversized header block');
                }
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower($name)][] = trim($value);
            }
        } finally {
            fclose($handle);
        }
        $host = $headers['host'][0] ?? null;
        if (!is_string($host) || str_contains($host, '/') || count($headers['host']) !== 1) {
            throw new \UnexpectedValueException('Exactly one Host header is required');
        }
        $ip = $input->getOption('client-ip');
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Invalid client IP');
        }
        $request = Request::create('https://' . $host . $line[2], $line[1], server: ['REMOTE_ADDR' => $ip]);
        $request->headers->replace($headers);
        $result = $this->classifier->classify($request, $this->catalogues->load());
        $output->writeln(json_encode([
            'operator' => $result->operator, 'agent' => $result->agent, 'kind' => $result->kind->value,
            'evidence' => $result->evidence->value, 'reason' => $result->reason,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
