<?php

declare(strict_types=1);

namespace App\Dto;

readonly class ReorderPoint
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public ?float $rop,
        public Demand $demand,
        public Lead $lead,
        public float $securityStock,
        public string $group,
        public float $zCoefficient,
        public int $leadDaysAdjustment,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'rop' => $this->rop,
            'securityStock' => $this->securityStock,
            'group' => $this->group,
            'zCoefficient' => $this->zCoefficient,
            'leadDaysAdjustment' => $this->leadDaysAdjustment,
        ];

        $data = array_merge($data, $this->prefixIndexKeys('demand', $this->demand->toArray()));

        return array_merge($data, $this->prefixIndexKeys('lead', $this->lead->toArray()));
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }

    private function prefixIndexKeys(string $prefix, array $data): array
    {
        return array_combine(
            array_map(
                function ($k) use ($prefix) {
                    return $prefix . ucfirst($k);
                },
                array_keys($data)
            ),
            $data
        );
    }
}
