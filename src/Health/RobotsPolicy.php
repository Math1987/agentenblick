<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

/** RFC 9309 rules, independently implemented from https://www.rfc-editor.org/rfc/rfc9309. */
final class RobotsPolicy
{
    public const MAX_BYTES = 512000;
    private const MAX_RULES = 4096;
    private const MAX_WORK = 32768;
    /** @var list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}> */
    private array $groups = [];

    public function __construct(string $text)
    {
        if (strlen($text) > self::MAX_BYTES || preg_match('//u', $text) !== 1) {
            throw new \UnexpectedValueException('robots_unsupported_document');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $agents = $rules = [];
        $seenRule = false;
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map(trim(...), explode(':', $line, 2));
            $name = strtolower($name);
            if ($name === 'user-agent') {
                if (!preg_match('/^(?:[a-z_-]+|\*)$/iD', $value)) {
                    continue;
                }
                if ($seenRule) {
                    $this->groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = $rules = [];
                    $seenRule = false;
                }
                $agents[] = strtolower($value);
                if (count($agents) > 128 || count($this->groups) > 256) {
                    throw new \LengthException('robots_policy_limit');
                }
                continue;
            }
            if (!in_array($name, ['allow', 'disallow'], true) || $agents === []) {
                continue;
            }
            $seenRule = true;
            if ($value === '') {
                continue;
            }
            if ($value[0] !== '/' && $value[0] !== '*') {
                continue;
            }
            if (strlen($value) > 2048 || ++$count > self::MAX_RULES) {
                throw new \LengthException('robots_policy_limit');
            }
            if (preg_match('/[\x00-\x20\x7f]/', $value)) {
                continue;
            }
            $rules[] = ['allow' => $name === 'allow', 'pattern' => self::normalize($value, true)];
        }
        if ($agents !== []) {
            $this->groups[] = ['agents' => $agents, 'rules' => $rules];
        }
    }

    /** @return list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}> */
    public function export(): array
    {
        return $this->groups;
    }

    /** @param mixed $groups */
    public static function restore(mixed $groups): self
    {
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 257) {
            throw new \UnexpectedValueException('robots_invalid_cache');
        }
        $policy = new self('');
        $count = 0;
        foreach ($groups as $group) {
            if (!is_array($group) || !is_array($group['agents'] ?? null) || !is_array($group['rules'] ?? null) || count($group['agents']) > 128) {
                throw new \UnexpectedValueException('robots_invalid_cache');
            }
            $agents = $rules = [];
            foreach ($group['agents'] as $agent) {
                if (!is_string($agent) || !preg_match('/^(?:[a-z_-]+|\*)$/D', $agent)) {
                    throw new \UnexpectedValueException('robots_invalid_cache');
                }
                $agents[] = $agent;
            }
            foreach ($group['rules'] as $rule) {
                if (!is_array($rule) || !is_bool($rule['allow'] ?? null) || !is_string($rule['pattern'] ?? null) || strlen($rule['pattern']) > 6144 || ++$count > self::MAX_RULES) {
                    throw new \UnexpectedValueException('robots_invalid_cache');
                }
                $rules[] = ['allow' => $rule['allow'], 'pattern' => $rule['pattern']];
            }
            $policy->groups[] = ['agents' => $agents, 'rules' => $rules];
        }
        return $policy;
    }

    /** Public site configuration only: comments and unrelated records are discarded. */
    public function normalized(): string
    {
        $lines = [];
        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                $lines[] = 'User-agent: ' . $agent;
            }
            foreach ($group['rules'] as $rule) {
                $lines[] = ($rule['allow'] ? 'Allow: ' : 'Disallow: ') . $rule['pattern'];
            }
            // An empty rule preserves a group boundary when re-parsed.
            if ($group['rules'] === []) {
                $lines[] = 'Disallow:';
            }
        }
        return implode("\n", $lines);
    }

    /** URI is request-local only. The result contains no URI or query input.
     * @return array{decision: string, matchedGroup: ?string, matchedRule: ?string, ruleHash: ?string, reasonCode: string}
     */
    public function decide(string $token, string $uri): array
    {
        $result = ['decision' => 'unknown', 'matchedGroup' => null, 'matchedRule' => null, 'ruleHash' => null, 'reasonCode' => 'robots_match_limit'];
        if (strlen($uri) > 8192 || !str_starts_with($uri, '/')) {
            return $result;
        }
        if (explode('?', $uri, 2)[0] === '/robots.txt') {
            return array_replace($result, ['decision' => 'allow', 'reasonCode' => 'robots_implicit_allow']);
        }
        $token = strtolower($token);
        $specific = array_values(array_filter($this->groups, static fn (array $group): bool => in_array($token, $group['agents'], true)));
        $selected = $specific !== [] ? $specific : array_values(array_filter($this->groups, static fn (array $group): bool => in_array('*', $group['agents'], true)));
        $result['matchedGroup'] = $selected === [] ? null : ($specific !== [] ? $token : '*');
        $target = self::normalize($uri, false);
        $work = self::MAX_WORK;
        $best = -1;
        $result['decision'] = 'allow';
        $result['reasonCode'] = 'robots_default_allow';
        foreach ($selected as $group) {
            foreach ($group['rules'] as $rule) {
                $match = self::matches($rule['pattern'], $target, $work);
                if ($match === null) {
                    return array_replace($result, ['decision' => 'unknown', 'matchedRule' => null, 'ruleHash' => null, 'reasonCode' => 'robots_match_limit']);
                }
                $literal = str_ends_with($rule['pattern'], '$') ? substr($rule['pattern'], 0, -1) : $rule['pattern'];
                // RFC 9309 requires percent-normalization before comparing octets.
                $specificity = strlen(str_replace('*', '', $literal));
                if (!$match || $specificity < $best || ($specificity === $best && !$rule['allow'])) {
                    continue;
                }
                $best = $specificity;
                $result['decision'] = $rule['allow'] ? 'allow' : 'disallow';
                $result['matchedRule'] = ($rule['allow'] ? 'Allow: ' : 'Disallow: ') . $rule['pattern'];
                $result['ruleHash'] = substr(hash('sha256', $result['matchedRule']), 0, 32);
                $result['reasonCode'] = 'robots_rule_match';
            }
        }
        return $result;
    }

    /** Glob matching with a shared deterministic work budget, never an unbounded regex. */
    private static function matches(string $pattern, string $target, int &$work): ?bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $p = $t = 0;
        $star = -1;
        $retry = 0;
        $patternLength = strlen($pattern);
        $targetLength = strlen($target);
        while (true) {
            if (--$work < 0) {
                return null;
            }
            if ($p === $patternLength && (!$anchored || $t === $targetLength)) {
                return true;
            }
            if ($p < $patternLength && $pattern[$p] === '*') {
                $star = $p++;
                $retry = $t;
                continue;
            }
            if ($t < $targetLength && $p < $patternLength && $pattern[$p] === $target[$t]) {
                ++$p;
                ++$t;
                continue;
            }
            if ($star >= 0 && $retry < $targetLength) {
                $p = $star + 1;
                $t = ++$retry;
                continue;
            }
            return false;
        }
    }

    private static function normalize(string $value, bool $pattern): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '%' && $i + 2 < $length && ctype_xdigit(substr($value, $i + 1, 2))) {
                $decoded = chr((int) hexdec(substr($value, $i + 1, 2)));
                $result .= preg_match('/^[a-z0-9._~-]$/iD', $decoded) ? $decoded : '%' . strtoupper(substr($value, $i + 1, 2));
                $i += 2;
            } elseif (ord($char) >= 128 || ord($char) <= 32 || ord($char) === 127 || $char === '%' || (!$pattern && in_array($char, ['*', '$'], true))) {
                $result .= '%' . strtoupper(bin2hex($char));
            } else {
                $result .= $char;
            }
        }
        return $result;
    }
}
