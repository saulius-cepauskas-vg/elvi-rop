<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Lead;
use App\Repository\Pm2Repository;
use App\Repository\VomRepository;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;

class LeadCalculator
{
    use DataTrait {
        getLead as private getLeadData;
    }

    use StandardDeviationTrait;

    private const MIN_LEAD_RECORDS = 20;

    private array $lead = [];

    private array $productVendorMap = [];

    private array $localCache = [];

    public function __construct(
        private CacheInterface $cache,
        private VomRepository $vomRepository,
        private Pm2Repository $pm2Repository,
        private array $staticLeadProductIds = [
            'DLTB-1',
            'LIPC3',
            'LIPC1',
            'LFNPC2',
            'DLAH-60',
            'DLFA-ED',
            'SB-2899',
            'PUSOLB-1',
            'SB-10057',
            'SB-10056',
            'LLRVC2',
            'LSZC2',
            'DLAH-1',
            'SB-2901',
            'DLLB-1',
            'SB-2908',
            'SB-2900',
            'SB-10053',
            'SB-10063',
            'LTB-1',
            'SB-10052',
        ]
    ) {
        $this->lead = $this->getLeadData();
        $this->productVendorMap = $this->getProductVendorMap();
    }

    public function setLead(array $lead): void
    {
        $this->lead = $lead;
    }

    public function setProductVendorMap(array $map): void
    {
        $this->productVendorMap = $map;
    }

    public function getLead(string $productId, string $variantId, array $groupOfVariantIds): Lead
    {
        if (in_array($productId, $this->staticLeadProductIds, true)) {
            return new Lead(
                $productId,
                $variantId,
                120, // 4 months
                48.989794855664, //  2 months
                'static_product_lead_time_120_60',
                0,
            );
        }

        return new Lead(
            $productId,
            $variantId,
            $this->getAverageLeadTimeInDays($productId, $groupOfVariantIds),
            $this->getLeadTimeStandardDeviation($productId, $groupOfVariantIds),
            $this->getLeadType($productId, $groupOfVariantIds),
            $this->getLeadRecordsCount($productId, $groupOfVariantIds),
        );
    }

    private function getLeadRecordsCount(string $productId, array $groupOfVariantIds): int
    {
        return count($this->getLeadTime($productId, $groupOfVariantIds)[0]);
    }

    private function getLeadType(string $productId, array $groupOfVariantIds): string
    {
        return $this->getLeadTime($productId, $groupOfVariantIds)[1];
    }

    public function getAverageLeadTimeInDays(string $productId, array $groupOfVariantIds): ?float
    {
        return $this->getLocalCached(
            sprintf('%s_%s_%s', __METHOD__, $productId, hash('xxh3', implode(',', $groupOfVariantIds))),
            function () use ($productId, $groupOfVariantIds) {
                $lead = $this->getLeadTime($productId, $groupOfVariantIds)[0];
                $days = array_map(fn ($item) => $item['days'], $lead);

                if (count($days) === 0) {
                    return null;
                }

                return array_sum($days) / count($days);
            }
        );
    }

    public function getLeadTimeStandardDeviation(string $productId, array $groupOfVariantIds): ?float
    {
        return $this->getLocalCached(
            sprintf('%s_%s_%s', __METHOD__, $productId, hash('xxh3', implode(',', $groupOfVariantIds))),
            function () use ($productId, $groupOfVariantIds) {
                $lead = $this->getLeadTime($productId, $groupOfVariantIds)[0];
                $days = array_map(fn ($item) => $item['days'], $lead);

                return $this->getStandardDeviation($days);
            }
        );
    }

    protected function getLeadTime(string $productId, array $groupOfVariantIds): array
    {
        return $this->getLocalCached(
            sprintf('%s_%s_%s', __METHOD__, $productId, hash('xxh3', implode(',', $groupOfVariantIds))),
            function () use ($productId, $groupOfVariantIds) {
                $dt = $this->dt('-12 months');

                $leadTimeVariant = $this->getVariantsGroupLeadTime($productId, $groupOfVariantIds);
                $leadTimeVariant12Month = $this->getLeadTimeFiltered($dt, $leadTimeVariant);
                if (count($leadTimeVariant12Month) >= self::MIN_LEAD_RECORDS) {
                    return [$leadTimeVariant12Month, 'variants_group_12_month'];
                }

                $leadTimeProduct = $this->getProductLeadTime($productId);
                $leadTimeProduct12Month = $this->getLeadTimeFiltered($dt, $leadTimeProduct);
                if (count($leadTimeProduct12Month) >= self::MIN_LEAD_RECORDS) {
                    return [$leadTimeProduct12Month, 'product_12_month'];
                }

                $vendor = $this->getProductVendor($productId);
                if ($vendor !== null) {
                    $leadTimeVendor = $this->getVendorLeadTime($vendor);
                    $leadTimeVendor12Month = $this->getLeadTimeFiltered($dt, $leadTimeVendor);
                    if (count($leadTimeVendor12Month) >= self::MIN_LEAD_RECORDS) {
                        return [$leadTimeVendor12Month, 'vendor_12_month'];
                    }
                }

                if (count($leadTimeVariant) > 0) {
                    return [$leadTimeVariant, 'variants_group_all_time'];
                }

                if (count($leadTimeProduct) > 0) {
                    return [$leadTimeProduct, 'product_all_time'];
                }

                return [$leadTimeVendor ?? [], 'vendor_all_time'];
            }
        );
    }

    private function getProductVendor(string $productId): ?string
    {
        return $this->productVendorMap[$productId] ?? null;
    }

    protected function getVendorLeadTime(string $vendor): array
    {
        $method = __METHOD__;
        $originalKey = sprintf('%s_%s', $method, $vendor);
        return $this->getLocalCached(
            $originalKey,
            function () use ($method, $originalKey) {
                if (($this->localCache['getVendorLeadTime-init'] ?? null) === null) {
                    foreach ($this->lead as $lead) {
                        $key = sprintf('%s_%s', $method, $lead['vendor']);
                        $this->localCache[$key] ??= [];
                        $this->localCache[$key][] = $lead;
                    }
                    $this->localCache['getVendorLeadTime-init'] = true;
                }

                return $this->localCache[$originalKey] ?? [];
            }
        );
    }

    protected function getVariantsGroupLeadTime(string $productId, array $groupOfVariantIds): array
    {
        return $this->getLocalCached(
            sprintf('%s_%s_%s', __METHOD__, $productId, hash('xxh3', implode(',', $groupOfVariantIds))),
            function () use ($productId, $groupOfVariantIds) {
                $indexed = array_flip($groupOfVariantIds);
                return array_filter(
                    $this->getProductLeadTime($productId),
                    fn ($item) => isset($indexed[$item['variant_id']])
                );
            }
        );
    }

    private function getProductLeadTime(string $productId): array
    {
        $method = __METHOD__;
        $originalKey = sprintf('%s_%s', $method, $productId);

        return $this->getLocalCached(
            $originalKey,
            function () use ($method, $originalKey) {
                if (($this->localCache['getProductLeadTime-init'] ?? null) === null) {
                    foreach ($this->lead as $lead) {
                        $key = sprintf('%s_%s', $method, $lead['product_id']);
                        $this->localCache[$key] ??= [];
                        $this->localCache[$key][] = $lead;
                    }
                    $this->localCache['getProductLeadTime-init'] = true;
                }

                return $this->localCache[$originalKey] ?? [];
            }
        );
    }

    protected function dt(string $modifier): DateTimeImmutable
    {
        return (new DateTimeImmutable())->modify($modifier);
    }

    private function getLeadTimeFiltered(DateTimeImmutable $from, array $leadTime): ?array
    {
        $leadTime = array_filter($leadTime, fn ($item) => $item['created_at'] > $from->format('Y-m-d'));

        usort($leadTime, function ($a, $b) {
            return $a['days'] <=> $b['days'];
        });

        return array_values($leadTime);
    }

    private function getLocalCached(string $key, callable $callback): mixed
    {
        if (($this->localCache[$key] ?? null) === null) {
            $this->localCache[$key] = $callback();
        }

        return $this->localCache[$key];
    }
}
