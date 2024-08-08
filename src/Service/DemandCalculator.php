<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Demand;
use App\Repository\DwhRepository;
use App\Repository\SosRepository;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;

class DemandCalculator
{
    use DataTrait {
        getDemand as private getDemandData;
    }
    use StandardDeviationTrait;

    private DateTimeImmutable $date;
    private array $demand = [];

    public function __construct(
        private CacheInterface $cache,
        private SosRepository $sosRepository,
        private DwhRepository $dwhRepository,
        private int $yearCount = 1,
    ) {
        $this->initDemand(new DateTimeImmutable(), $this->yearCount);
    }

    public function initDemand(DateTimeImmutable $date, int $yearCount): void
    {
        $this->date = $date;

        $dateFrom = $this->date->modify(sprintf('-%d year', $yearCount))->format('Y-m-d 00:00:00');
        $dateTo = $this->date->modify('-1 day')->format('Y-m-d 23:59:59');

        $demand = array_filter($this->getDemandData(), function ($item) use ($dateFrom, $dateTo) {
            return !empty($item['order_created_at']) && $item['order_created_at'] >= $dateFrom && $item['order_created_at'] <= $dateTo;
        });

        $this->demand = [];
        foreach ($demand as $item) {
            $this->demand[$item['variant_id']] ??= [];
            $this->demand[$item['variant_id']][] = $item;
        }
    }

    public function getDemand(string $productId, string $variantId): Demand
    {
        return new Demand(
            $productId,
            $variantId,
            $this->getDemandAveragePerDay($variantId),
            $this->getDemandStandardDeviationDaily($variantId),
            $this->getMonthlyDemand($variantId),
        );
    }

    public function getDemandAveragePerDay(string $variantId): ?float
    {
        $demand = $this->getVariantDemand($variantId);
        if (count($demand) === 0) {
            return null;
        }

        $sum = array_sum(
            array_map(
                function ($item) {
                    return $item['quantity'];
                },
                $demand
            )
        );

        return $sum / (365 * $this->yearCount);
    }

    public function getDemandStandardDeviationDaily(string $variantId): ?float
    {
        $std = $this->getStandardDeviation(array_values($this->getMonthlyDemand($variantId)));

        return $std === null ? null : $std / 30.42;
    }

    private function getMonthlyDemand(string $variantId): array
    {
        $demand = $this->getVariantDemand($variantId);
        $initialDate = $this->date->modify('-1 day');

        $sums = [];
        for ($i = 0; $i < 12; $i++) {
            $date = $initialDate->modify(sprintf('-%d month', $i));
            $dateTo = $date->format('Y-m-d 23:59:59');
            $dateFrom = $date->modify('-1 month')->modify('+1 day')->format('Y-m-d 00:00:00');

            $sums[$i] = array_sum(
                array_map(
                    fn ($item) => $item['quantity'],
                    array_filter(
                        $demand,
                        fn ($item) => !empty($item['order_created_at'])
                            && $item['order_created_at'] >= $dateFrom
                            && $item['order_created_at'] < $dateTo
                    )
                )
            );
        }

        return $sums;
    }

    private function getVariantDemand(string $variantId): array
    {
        return $this->demand[$variantId] ?? [];
    }
}
