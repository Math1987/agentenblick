<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

final class Comparison
{
    /** Keep only published metrics: a malformed service response fails closed.
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, string $period, string $cohort): array
    {
        $cells = [];
        foreach (is_array($input['cells'] ?? null) ? $input['cells'] : [] as $cell) {
            if (!is_array($cell) || ($cell['period'] ?? null) !== $period || ($cell['cohortId'] ?? null) !== $cohort || !is_array($cell['metrics'] ?? null)) {
                continue;
            }
            $metrics = ['aiSessionsPct' => self::metric($cell['metrics']['aiSessionsPct'] ?? null)];
            $published = is_array($metrics['aiSessionsPct']);
            foreach (['byKind' => Payload::KINDS, 'byEvidence' => Payload::EVIDENCE, 'byOperator' => Payload::OPERATORS] as $field => $keys) {
                $metrics[$field] = [];
                $source = is_array($cell['metrics'][$field] ?? null) ? $cell['metrics'][$field] : [];
                foreach ($keys as $key) {
                    $metrics[$field][$key] = self::metric($source[$key] ?? null);
                    $published = $published || is_array($metrics[$field][$key]);
                }
            }
            if ($published) {
                $cells[] = ['period' => $period, 'cohortId' => $cohort, 'metrics' => $metrics];
            }
        }
        [$country, $shopware, $bucket, $extension] = explode('|', $cohort);
        $cohorts = $cells === [] ? [] : [['id' => $cohort, 'country' => $country, 'shopware' => $shopware, 'trafficBucket' => $bucket, 'agenticCommerceExtension' => $extension === '1']];
        return ['demo' => false, 'methodologyVersion' => '1', 'periods' => [$period], 'cohorts' => $cohorts, 'cells' => $cells, 'status' => $cells === [] ? 'insufficient_data' : 'published'];
    }

    /** @return array{p25: float, median: float, p75: float, installations: int}|string */
    private static function metric(mixed $input): array|string
    {
        if (!is_array($input) || !is_int($input['installations'] ?? null) || $input['installations'] < 5) {
            return 'suppressed';
        }
        foreach (['p25', 'median', 'p75'] as $key) {
            if ((!is_int($input[$key] ?? null) && !is_float($input[$key] ?? null)) || $input[$key] < 0 || $input[$key] > 100) {
                return 'suppressed';
            }
        }
        if ($input['p25'] > $input['median'] || $input['median'] > $input['p75']) {
            return 'suppressed';
        }
        return ['p25' => (float) $input['p25'], 'median' => (float) $input['median'], 'p75' => (float) $input['p75'], 'installations' => $input['installations']];
    }
}
