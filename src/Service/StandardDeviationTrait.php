<?php

declare(strict_types=1);

namespace App\Service;

trait StandardDeviationTrait
{
    protected function getStandardDeviation(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $average = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $i) {
            $variance += pow($i - $average, 2);
        }

        return sqrt($variance) / sqrt(count($values) - 1);
    }
}
