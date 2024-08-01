<?php

declare(strict_types=1);

namespace App\Dto;

readonly class Lead
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public ?float $averageLeadTimeInDays,
        public ?float $leadTimeStandardDeviation,
        public string $leadType,
        public int $leadTimeRecordsCount,
    ) {
    }

    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'averageLeadTimeInDays' => $this->averageLeadTimeInDays,
            'leadTimeStandardDeviation' => $this->leadTimeStandardDeviation,
            'leadType' => $this->leadType,
            'leadTimeRecordsCount' => $this->leadTimeRecordsCount,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
