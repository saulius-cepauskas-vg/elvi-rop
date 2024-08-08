<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Demand;
use App\Dto\Lead;
use DateTimeImmutable;

class ForecastDemandCalculator
{
    use StandardDeviationTrait;

    private array $data;

    public function __construct()
    {
        $this->data = json_decode(file_get_contents('forecast.json'), true);
    }

    public function getDemand(string $productId, string $variantId, DateTimeImmutable $today, Lead $lead): Demand
    {
        $demand = [];
        $weeks = (int)ceil($lead->averageLeadTimeInDays / 7);
        foreach ($this->get($variantId) as $data) {
            $nextMonday = $today->modify('next monday');

            $dt = new DateTimeImmutable(sprintf('@%d', $data['timestamp']['$date'] / 1000));
            if ($dt->format('Y-m-d') === $nextMonday->format('Y-m-d')) {
                $demand[] = $data['value'];
                $weeks--;
                if ($weeks === 0) {
                    break;
                }

                $today = $nextMonday;
            }
        }

        return new Demand(
            $productId,
            $variantId,
            array_sum($demand) / count($demand),
            $this->getStandardDeviation($demand),
            $demand,
        );
    }

    public function hasDemand(string $variantId): bool
    {
        return $this->get($variantId) !== null;
    }

    private function get(string $variantId): ?array
    {
        foreach ($this->data['result'] as $data) {
            if ($data['partNumber'] === $variantId) {
                return $data['demandForecast']['consensus'];
            }
        }

        return null;
    }
}
