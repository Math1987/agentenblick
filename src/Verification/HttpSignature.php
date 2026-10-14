<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Bakame\Http\StructuredFields\Bytes;
use Bakame\Http\StructuredFields\Dictionary;
use Bakame\Http\StructuredFields\InnerList;
use Bakame\Http\StructuredFields\Item;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HttpSignature
{
    /** @return array<string, array{input: InnerList, signature: string}> */
    public function parse(string $inputHeader, string $signatureHeader): array
    {
        if (strlen($inputHeader) > 16384 || strlen($signatureHeader) > 16384) {
            throw new \UnexpectedValueException('signature_too_large');
        }
        $inputs = Dictionary::fromRfc8941($inputHeader);
        $signatures = Dictionary::fromRfc8941($signatureHeader);
        if (count($inputs) > 8) {
            throw new \UnexpectedValueException('too_many_signatures');
        }
        $result = [];
        foreach ($inputs->toAssociative() as $label => $input) {
            if (!$input instanceof InnerList || !$signatures->hasKeys($label)) {
                continue;
            }
            $signature = $signatures->getByKey($label);
            if ($signature instanceof Item && $signature->value() instanceof Bytes) {
                $result[$label] = ['input' => $input, 'signature' => $signature->value()->decoded()];
            }
        }
        return $result;
    }

    public function base(Request|Response $message, InnerList $input): string
    {
        $lines = [];
        $seen = [];
        foreach ($input as $component) {
            $name = $component->value();
            $identifier = $component->toRfc8941();
            if (!is_string($name) || $name !== strtolower($name) || isset($seen[$identifier])) {
                throw new \UnexpectedValueException('invalid_signature_component');
            }
            $seen[$identifier] = true;
            $parameters = $component->parameters();
            if (!$parameters->allowedKeys(['sf', 'key'])) {
                throw new \UnexpectedValueException('unsupported_component_parameter');
            }
            if (str_starts_with($name, '@')) {
                if (count($parameters) !== 0) {
                    throw new \UnexpectedValueException('unsupported_derived_parameter');
                }
                $value = $this->derived($message, $name);
            } else {
                if (!$message->headers->has($name)) {
                    throw new \UnexpectedValueException('missing_signed_header');
                }
                $value = implode(', ', array_map(static fn (string $value): string => trim($value, " \t"), $message->headers->all($name)));
                $key = $component->parameterByKey('key');
                if ($key !== null) {
                    if (!is_string($key)) {
                        throw new \UnexpectedValueException('invalid_dictionary_key');
                    }
                    $value = Dictionary::fromRfc8941($value)->getByKey($key)->toRfc8941();
                } elseif ($component->parameterByKey('sf') === true) {
                    // Fields known to this verifier as structured dictionaries.
                    if (!in_array($name, ['signature-agent', 'content-digest'], true)) {
                        throw new \UnexpectedValueException('unknown_structured_field');
                    }
                    $value = Dictionary::fromRfc8941($value)->toRfc8941();
                }
            }
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new \UnexpectedValueException('invalid_component_value');
            }
            $lines[] = $identifier . ': ' . $value;
        }
        $lines[] = '"@signature-params": ' . $input->toRfc8941();
        return implode("\n", $lines);
    }

    public function agentOrigin(string $header, string $label): string
    {
        if (strlen($header) > 2048) {
            throw new \UnexpectedValueException('signature_agent_too_large');
        }
        $item = str_starts_with(ltrim($header), '"')
            ? Item::fromRfc8941($header)
            : Dictionary::fromRfc8941($header)->getByKey($label);
        if (!$item instanceof Item || !is_string($item->value()) || count($item->parameters()) !== 0) {
            throw new \UnexpectedValueException('invalid_signature_agent');
        }
        return $item->value();
    }

    private function derived(Request|Response $message, string $name): string
    {
        if ($message instanceof Response) {
            return $name === '@status' ? (string) $message->getStatusCode() : throw new \UnexpectedValueException('unsupported_response_component');
        }
        $target = $message->getRequestUri();
        return match ($name) {
            '@authority' => $message->getHttpHost(),
            '@target-uri' => $message->getSchemeAndHttpHost() . $target,
            '@method' => $message->getMethod(),
            '@scheme' => $message->getScheme(),
            '@request-target' => $target,
            '@path' => explode('?', $target, 2)[0],
            '@query' => str_contains($target, '?') ? '?' . explode('?', $target, 2)[1] : '?',
            default => throw new \UnexpectedValueException('unsupported_derived_component'),
        };
    }
}
