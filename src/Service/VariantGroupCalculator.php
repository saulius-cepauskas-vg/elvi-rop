<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DwhRepository;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;

class VariantGroupCalculator
{
    use DataTrait;
    use StandardDeviationTrait;

    private array $variantVolumeGroups = [];

    private array $variantVariationGroups = [];

    private array $localCache = [
        'volume' => [],
        'variation' => [],
        'intersection' => [],
    ];

    private int $total;

    public function __construct(
        private CacheInterface $cache,
        private DwhRepository $dwhRepository,
        private array $groupsVolume = [
            'A+' => 0.5,
            'A' => 0.75,
            'B' => 0.9,
            'C' => 1,
        ],
        private array $groupsVariation = [
            'X' => 0.34,
            'Y' => 1.47,
            'Z' => 10,
        ],
    ) {
    }

    public function calculateVariantGroups(DateTimeImmutable $date, int $years = 1): void
    {
        $this->localCache = [
            'volume' => [],
            'variation' => [],
            'intersection' => [],
        ];

        $demand = $this->getDemand();

        $dateFrom = $date->modify(sprintf('-%d year', $years));
        $dateTo = $date->modify('-1 day')->setTime(23, 59, 59);

        $daysAdjustment = $dateFrom
            ->setDate(
                (int)$dateFrom->format('Y'),
                (int)$dateFrom->format('m'),
                1
            )
            ->diff($dateFrom)
            ->days;

        $this->total = 0;

        $variants = [];
        $monthlyDemand = [];
        foreach ($demand as $item) {
            $itemDate = new DateTimeImmutable($item['order_created_at']);
            if ($itemDate < $dateFrom || $itemDate > $dateTo) {
                continue;
            }

            if (empty($item['variant_id'])) {
                continue;
            }

            $this->total += $item['quantity'];

            $variants[$item['variant_id']] ??= [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'quantity' => 0,
            ];

            $variants[$item['variant_id']]['quantity'] += $item['quantity'];

            if (($monthlyDemand[$item['variant_id']] ?? null) === null) {
                $monthlyDemand[$item['variant_id']] = [];
                for ($i = 0; $i < 12 * $years; $i++) {
                    $month = $dateFrom
                        ->setDate((int)$dateFrom->format('Y'), (int)$dateFrom->format('m'), 1)
                        ->modify(sprintf('+%d month', $i))
                        ->format('Y-m-01');

                    $monthlyDemand[$item['variant_id']][$month] = 0;
                }
            }

            $month = date(
                'Y-m-01',
                strtotime(sprintf('-%d days', $daysAdjustment), strtotime($item['order_created_at']))
            );

            if (($monthlyDemand[$item['variant_id']][$month] ?? null) === null) {
                throw new \Exception(
                    sprintf(
                        'Invalid month %s (range %s-%s)',
                        $month,
                        $dateFrom->format('Y-m-d'),
                        $dateTo->format('Y-m-d')
                    )
                );
            }

            $monthlyDemand[$item['variant_id']][$month] += $item['quantity'];
        }

        usort($variants, function ($a, $b) {
            return $b['quantity'] <=> $a['quantity'];
        });

        $prevVolume = 0;
        foreach ($variants as $item) {
            $volume = $item['quantity'] / $this->total + $prevVolume;

            $group = array_key_last($this->groupsVolume);
            foreach ($this->groupsVolume as $key => $value) {
                if ($volume <= $value) {
                    $group = $key;
                    break;
                }
            }

            $this->variantVolumeGroups[$group] ??= [];
            $this->variantVolumeGroups[$group][] = $item['variant_id'];
            $this->localCache['volume'][$item['variant_id']] = $group;
            $prevVolume = $volume;
        }

        foreach ($monthlyDemand as $variantId => $monthly) {
            $demand = array_values($monthly);
            $std = $this->getStandardDeviation($demand);
            $avg = array_sum($demand) / count($demand);
            $variation = $std / $avg;

            $group = array_key_last($this->groupsVariation);
            foreach ($this->groupsVariation as $key => $value) {
                if ($variation <= $value) {
                    $group = $key;
                    break;
                }
            }

            $this->variantVariationGroups[$group] ??= [];
            $this->variantVariationGroups[$group][] = $variantId;
            $this->localCache['variation'][$variantId] = $group;
        }
    }

    public function getStats(): array
    {
        $pad = 6;
        $intersect = fn ($vol, $var): string => (string)count(
            array_intersect($this->variantVolumeGroups[$vol] ?? [], $this->variantVariationGroups[$var] ?? [])
        );

        return [
            'total' => $this->total,
            'variants' => count($this->localCache['volume']),
            'distribution' => [
                sprintf(
                    '%s %s %s %s',
                    ' ',
                    str_pad('X', $pad, ' ', STR_PAD_LEFT),
                    str_pad('Y', $pad, ' ', STR_PAD_LEFT),
                    str_pad('Z', $pad, ' ', STR_PAD_LEFT),
                ),
                sprintf(
                    '%s %s %s %s',
                    'A+',
                    str_pad($intersect('A+', 'X'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('A+', 'Y'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('A+', 'Z'), $pad, ' ', STR_PAD_LEFT),
                ),
                sprintf(
                    '%s %s %s %s',
                    'A',
                    str_pad($intersect('A', 'X'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('A', 'Y'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('A', 'Z'), $pad, ' ', STR_PAD_LEFT),
                ),
                sprintf(
                    '%s %s %s %s',
                    'B',
                    str_pad($intersect('B', 'X'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('B', 'Y'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('B', 'Z'), $pad, ' ', STR_PAD_LEFT),
                ),
                sprintf(
                    '%s %s %s %s',
                    'C',
                    str_pad($intersect('C', 'X'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('C', 'Y'), $pad, ' ', STR_PAD_LEFT),
                    str_pad($intersect('C', 'Z'), $pad, ' ', STR_PAD_LEFT),
                ),
            ]
        ];
    }

    public function getGroupVariantIds(string $variantId): array
    {
        $key = $this->getGroupKey($variantId);
        if (($this->localCache['intersection'][$key] ?? null) === null) {
            $this->localCache['intersection'][$key] = array_intersect(
                $this->variantVolumeGroups[$this->getVolumeGroup($variantId)],
                $this->variantVariationGroups[$this->getVariationGroup($variantId)]
            );
        }

        return $this->localCache['intersection'][$key];
    }

    public function getVolumeGroup(string $variantId): string
    {
        return $this->localCache['volume'][$variantId] ?? array_key_last($this->groupsVolume);
    }

    public function getVariationGroup(string $variantId): string
    {
        return $this->localCache['variation'][$variantId] ?? array_key_last($this->groupsVariation);
    }

    public function getGroupKey(string $variantId): string
    {
        return sprintf('%s %s', $this->getVolumeGroup($variantId), $this->getVariationGroup($variantId));
    }
}
