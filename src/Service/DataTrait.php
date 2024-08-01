<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DwhRepository;
use App\Repository\Pm2Repository;
use App\Repository\VomRepository;
use Symfony\Contracts\Cache\CacheInterface;

trait DataTrait
{
    private array $demand = [];
    private array $lead = [];

    private CacheInterface $cache;
    private DwhRepository $dwhRepository;
    private VomRepository $vomRepository;
    private Pm2Repository $pm2Repository;

    private bool $isCacheEnabled = true;

    private function getCached(string $key, callable $callback): mixed
    {
        if ($this->isCacheEnabled) {
            return $this->cache->get($key, $callback);
        }

        return $callback();
    }

    private function getDemand(): array
    {
        return $this->getCached(sprintf('demand_%s', date('Y-m-d')), function () {
            if (empty($this->demand)) {
                $this->demand = $this->dwhRepository->getItems();
            }

            return $this->demand;
        });
    }

    private function getLead(): array
    {
        return $this->getCached(sprintf('lead_%s', date('Y-m-d')), function () {
            if (empty($this->lead)) {
                $this->lead = $this->vomRepository->getLeadTimeItems();
            }

            return $this->lead;
        });
    }

    private function getProductVendorMap(): array
    {
        return $this->getCached(sprintf('product_vendor_%s', date('Y-m-d')), function () {
            return $this->pm2Repository->getProductVendorMap();
        });
    }
}
