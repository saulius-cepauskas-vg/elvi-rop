<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Demand;
use App\Dto\Lead;
use App\Dto\ReorderPoint;
use DateTimeImmutable;

class ReorderPointCalculator
{
    public function __construct(
        private VariantGroupCalculator $variantGroupCalculator,
        private DemandCalculator $demandCalculator,
        private LeadCalculator $leadCalculator,

        private array $coefficientsOfService = [
//            'A+ X' => 2.33, // 99%
//            'A+ Y' => 1.88, // 97%
//            'A+ Z' => 1.48, // 93%
//
//            'A X' => 1.88, // 97%
//            'A Y' => 1.64, // 95%
//            'A Z' => 1.48, // 93%
//
//            'B X' => 1.64, // 95%
//            'B Y' => 1.28, // 90%
//            'B Z' => 0.84, // 80%
//
//            'C X' => 0.84, // 80%
//            'C Y' => 0, // 0%
//            'C Z' => 0, // 0%

// ----------------------------------------


            'A+ X' => 1.88, // 97%
            'A+ Y' => 1.64, // 95%
            'A+ Z' => 1.48, // 93%

            'A X' => 1.34, // 91%
            'A Y' => 1.28, // 90%
            'A Z' => 1.23, // 89%

            'B X' => 1.01, // 85%
            'B Y' => 0.84, // 80%
            'B Z' => 0.52, // 70%

            'C X' => 0.52, // 70%
            'C Y' => 0, // 0%
            'C Z' => 0, // 0%
        ]
    ) {
    }

    public function initDemand(): void
    {
        $this->demandCalculator->initDemand(new DateTimeImmutable(), 1);
    }

    public function calculate(string $variantId, string $productId, int $leadDaysAdjustment = 0): ReorderPoint
    {
        $demand = $this->demandCalculator->getDemand($productId, $variantId);

        $lead = $this->leadCalculator->getLead(
            $productId,
            $variantId,
            $this->variantGroupCalculator->getGroupVariantIds($variantId),
        );

        $coefficientOfService = $this->getCoefficientOfService($variantId);
        $securityStock = $this->getSecurityStock($demand, $lead, $leadDaysAdjustment, $coefficientOfService);

        $leadTimeInDays = $lead->averageLeadTimeInDays + $leadDaysAdjustment;
        $rop = $demand->demandAveragePerDay === null || $lead->averageLeadTimeInDays === null
            ? null :
            floor($securityStock + $demand->demandAveragePerDay * $leadTimeInDays);

        return new ReorderPoint(
            $productId,
            $variantId,
            $rop,
            $demand,
            $lead,
            $securityStock,
            $this->variantGroupCalculator->getGroupKey($variantId),
            $coefficientOfService,
            $leadDaysAdjustment,
        );
    }

    private function getSecurityStock(
        Demand $demand,
        Lead $lead,
        int $leadTimeAdjustment,
        float $coefficientOfService
    ): float {
        if ($lead->averageLeadTimeInDays === null) {
            return 0;
        }

        $leadTimeInDays = $lead->averageLeadTimeInDays + $leadTimeAdjustment;

        $stock =
            $coefficientOfService * $demand->demandStandardDeviation * sqrt($leadTimeInDays)
            + $coefficientOfService * $demand->demandAveragePerDay * $lead->leadTimeStandardDeviation;

        return ceil($stock);
    }

    private function getCoefficientOfService(string $variantId): float
    {
        return $this->coefficientsOfService[$this->variantGroupCalculator->getGroupKey($variantId)] ?? 0;
    }
}
