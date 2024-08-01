<?php

declare(strict_types=1);

namespace App\Dto;

readonly class Demand
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public ?float $demandAveragePerDay,
        public ?float $demandStandardDeviation,
        public array $monthlyDemand
    ) {
    }

    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'demandAveragePerDay' => $this->demandAveragePerDay,
            'demandStandardDeviation' => $this->demandStandardDeviation,
            'monthlyDemand' => implode(',', $this->monthlyDemand),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
