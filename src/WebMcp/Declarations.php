<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\WebMcp;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Persistence\AggregateCorrection;
use Aithos\Agentenblick\Persistence\DailyKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Symfony\Component\HttpFoundation\Request;

final class Declarations
{
    public function __construct(private readonly Connection $connection, private readonly DailyKey $keys, private readonly Scope $scope)
    {
    }

    public function declare(Request $request, Declaration $declaration, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->scope->prepare($request)) {
            throw new \RuntimeException('session_unavailable');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $key = $this->keys->current($now->format('Y-m-d'));
        try {
            $sessionKey = Scope::sessionKey($request, $key);
        } finally {
            sodium_memzero($key);
        }
        if ($sessionKey === null) {
            throw new \RuntimeException('session_unavailable');
        }
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $lock = 'agentenblick:' . substr($sessionKey, 0, 48);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) {
            throw new \RuntimeException('session_busy');
        }
        try {
            $this->connection->transactional(function (Connection $connection) use ($request, $declaration, $now, $sessionKey): void {
                $session = $connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE session_key = ? AND sales_channel_id = ? AND webmcp_scope = 1 AND last_seen >= ? AND first_seen <= ? ORDER BY last_seen DESC LIMIT 1 FOR UPDATE', [$sessionKey, $request->attributes->get('sw-sales-channel-id'), $now->modify('-30 minutes')->format('Y-m-d H:i:s.v'), $now->format('Y-m-d H:i:s.v')]);
                if ($session === false || $session['closed_at'] !== null) {
                    throw new \RuntimeException('session_unavailable');
                }
                if ($session['declared_service'] !== '') {
                    if ($session['declared_service'] !== $declaration->service || $session['declared_intent'] !== $declaration->intent) {
                        throw new \RuntimeException('declaration_conflict');
                    }
                    return;
                }
                $changes = ['declared_service' => $declaration->service, 'declared_intent' => $declaration->intent, 'declared_at' => $now->format('Y-m-d H:i:s.v')];
                // An explicit statement outranks a heuristic, never an observed identity
                // or a cryptographic/network proof. These declarations remain separate.
                if (in_array($session['evidence'], [Evidence::None->value, Evidence::Suspected->value], true)) {
                    $classification = $declaration->classification();
                    $changes += ['operator' => $classification->operator, 'agent' => $classification->agent, 'kind' => $classification->kind->value, 'evidence' => $classification->evidence->value, 'detected_at' => $now->format('Y-m-d H:i:s.v'), 'journey_partial' => 1];
                }
                $connection->update('agentenblick_session', $changes, ['id' => $session['id']]);
                $from = $session + ['day' => substr($session['first_seen'], 0, 10), 'browser_family' => ''];
                AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, $changes));
                // The POST is a declaration, not a product navigation. No request,
                // session increment, historical request rewrite or journey is invented.
            });
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function classify(Request $request, Classification $observed, string $key, \DateTimeImmutable $at): Classification
    {
        if (!in_array($observed->evidence, [Evidence::None, Evidence::Suspected], true)) {
            return $observed;
        }
        $sessionKey = Scope::sessionKey($request, $key);
        if ($sessionKey === null) {
            return $observed;
        }
        $row = $this->connection->fetchAssociative('SELECT declared_service, declared_intent FROM agentenblick_session WHERE session_key = ? AND sales_channel_id = ? AND webmcp_scope = 1 AND declared_at IS NOT NULL AND declared_at <= ? AND last_seen >= ? AND closed_at IS NULL ORDER BY last_seen DESC LIMIT 1', [$sessionKey, $request->attributes->get('sw-sales-channel-id'), $at->format('Y-m-d H:i:s.v'), $at->modify('-30 minutes')->format('Y-m-d H:i:s.v')]);
        return $row === false ? $observed : (new Declaration($row['declared_service'], $row['declared_intent']))->classification();
    }
}
