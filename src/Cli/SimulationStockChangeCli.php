<?php

declare(strict_types=1);

namespace App\Cli;

use App\Dto\ReorderPoint;
use App\Repository\LogisticsRepository;
use App\Repository\Pm2Repository;
use App\Repository\SosRepository;
use App\Repository\VomRepository;
use App\Service\DataTrait;
use App\Service\DemandCalculator;
use App\Service\ForecastDemandCalculator;
use App\Service\ReorderPointCalculator;
use App\Service\VariantGroupCalculator;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(name: 'simulation:stock-change')]
class SimulationStockChangeCli extends Command
{
    use DataTrait;
    use OutputTrait;

    private string $startingDate = '2023-01-02'; //2024-07-29
    private int $iterationsCount = 144;
    private bool $useForecastInsteadDemand = false;

    public function __construct(
        private SosRepository $sosRepository,
        private ForecastDemandCalculator $forecastDemandCalculator,
        private VariantGroupCalculator $variantGroupCalculator,
        private LogisticsRepository $logisticsRepository,
        private ReorderPointCalculator $ropCalculator,
        private Pm2Repository $pm2Repository,
        private CacheInterface $cache,
        private DemandCalculator $demandCalculator,
        private VomRepository $vomRepository
    ) {
        parent::__construct();
    }

    // todo:
    // 1. split rax | pixi
    // 2. round pixi items to boxes / palettes
    // 3. substitution

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // monday
        $startDate = new DateTimeImmutable($this->startingDate);

        $vendorOrders = $this->getVendorOrders($startDate);
        $stock = $this->getStock($startDate);
        $demand = $this->getDemand();

        $inventory = $this->getSimulationInventory($stock, $demand);

        $result = $this->simulateOrdering($io, $startDate, null, $stock, $demand, $vendorOrders, $inventory);

        $initialQuantityMap = [];
        $csv = array_map(
            function ($item) use (&$initialQuantityMap) {
                $initialQuantityMap[$item['ropObject']->variantId] ??= $item['quantity'];
                return [
                    'date' => $item['date'],
                    'lead' => $item['lead'],
                    'variant_id' => $item['ropObject']->variantId,
                    'product_id' => $item['ropObject']->productId,
                    'quantity' => $item['quantity'],
                    'stock' => $item['stock'],
                    'variant_in_vendor_orders' => $item['variantInVendorOrders'],
                    'accumulated_stock' => $item['stock'] + $item['variantInVendorOrders'],

                    'rop' => $item['ropObject']->adjustedRop,
                    'rop_security_stock' => $item['ropObject']->adjustedSecurityStock,
                    'rop_z_coefficient' => $item['ropObject']->zCoefficient,
                    'rop_group' => $item['ropObject']->group,
                    'rop_lead_days_adjustment' => $item['ropObject']->leadDaysAdjustment,

                    'lead_average_time_in_days' => $item['ropObject']->lead->averageLeadTimeInDays,
                    'lead_time_std' => $item['ropObject']->lead->leadTimeStandardDeviation,
                    'lead_records_count' => $item['ropObject']->lead->leadTimeRecordsCount,
                    'lead_type' => $item['ropObject']->lead->leadType,

                    'demand_average_per_day' => $item['ropObject']->demand->demandAveragePerDay,
                    'demand_std' => $item['ropObject']->demand->demandStandardDeviation,
                    'demand_monthly' => implode(',', $item['ropObject']->demand->monthlyDemand),

                    'lead_random_days' => $item['leadRandomDays'],

                    'real_rop' => $item['ropObject']->rop,
                    'real_rop_security_stock' => $item['ropObject']->securityStock,

                    'id' => sprintf(
                        '%s %s %d',
                        $item['ropObject']->productId,
                        $item['ropObject']->variantId,
                        $initialQuantityMap[$item['ropObject']->variantId]
                    )
                ];
            },
            $result
        );

        $this->csv($csv, sprintf('vo_simulation_%s', date('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }

    private function simulateOrdering(
        SymfonyStyle $io,
        DateTimeImmutable $today,
        ?DateTimeImmutable $lastPurchaseDate,
        array $stock,
        array $demand,
        array $vendorOrders,
        array $inventory,
        int $iteration = 0,
        int $leadDaysAdjustment = 4
    ): array {
        $io->info(sprintf('Ordering for %s (%d iteration)', $today->format('Y-m-d'), $iteration + 1));

        if ($inventory === []) {
            return [];
        }

        $newInventory = [];
        if ($lastPurchaseDate !== null) {
            // reduce demand from stock
            $demandBetweenTodayAndLastPurchase = $this->getDemandBetween($demand, $lastPurchaseDate, $today);
            foreach ($demandBetweenTodayAndLastPurchase as $variantDemand) {
                // todo: remove me
                if (!isset($inventory[$variantDemand['variant_id']])) {
                    continue;
                }

                $newInventory[$variantDemand['variant_id']] = $inventory[$variantDemand['variant_id']] = [
                    'variant_id' => $variantDemand['variant_id'],
                    'product_id' => $variantDemand['product_id']
                ];

                if (!isset($stock[$variantDemand['variant_id']])) {
                    $stock[$variantDemand['variant_id']] = [
                        'stock' => $variantDemand['quantity'] * -1,
                        'variant_id' => $variantDemand['variant_id'],
                        'product_id' => $variantDemand['product_id']
                    ];
                    continue;
                }

                $stock[$variantDemand['variant_id']]['stock'] -= $variantDemand['quantity'];
            }
        }

        foreach ($vendorOrders as $vendorOrderVariantId => $variantVendorOrders) {
            foreach ($variantVendorOrders as $variantVendorOrderIndex => $variantVendorOrder) {
                if ($variantVendorOrder['lead'] > $today->format('Y-m-d')) {
                    continue;
                }
                if (empty($stock[$vendorOrderVariantId])) {
                    continue;
                }
//                $stock[$vendorOrderVariantId] ??= [
//                    'stock' => 0,
//                    'variant_id' => $vendorOrderVariantId,
//                    'product_id' => $variantVendorOrder['product_id']
//                ];
                $stock[$vendorOrderVariantId]['stock'] += $variantVendorOrder['quantity'];
                unset($vendorOrders[$vendorOrderVariantId][$variantVendorOrderIndex]);
            }
        }

        $result = [];
        $ROPs = $this->getROPs($io, $today, $inventory, $leadDaysAdjustment);
        foreach ($ROPs as $rop) {
            $variantStock = (int)($stock[$rop->variantId]['stock'] ?? 0);
            $variantInVendorOrders = array_sum(
                array_map(fn ($order) => $order['quantity'], $vendorOrders[$rop->variantId] ?? [])
            );

            $diff = 0;
            if ($variantStock <= $rop->rop) {
                $diff = ceil(
                    $rop->adjustedRop - ($leadDaysAdjustment * $rop->demand->demandAveragePerDay) - $variantStock - $variantInVendorOrders + (($rop->lead->averageLeadTimeInDays + $leadDaysAdjustment) * $rop->demand->demandAveragePerDay)
                );
            }

            $newInventory[$rop->variantId] = [
                'variant_id' => $rop->variantId,
                'product_id' => $rop->productId,
            ];

            $leadRandomDays = 0;

            $vendorOrders[$rop->variantId] ??= [];
            $result[] = $vendorOrders[$rop->variantId][] = [
                'ropObject' => $rop,
                'rop' => $rop->rop,
                'stock' => $variantStock,
                'variantInVendorOrders' => $variantInVendorOrders,
                'quantity' => max($diff, 0),
                'date' => $today->format('Y-m-d'),
                'leadRandomDays' => $leadRandomDays,
//                'lead' => $today->modify(sprintf('+%d days', $rop->lead->averageLeadTimeInDays))->format('Y-m-d')
                'lead' => $today->modify(
                    sprintf('+%d days', ($rop->lead->averageLeadTimeInDays + $leadRandomDays))
                )->format('Y-m-d')
            ];
        }

        if ($iteration >= $this->iterationsCount) {
            return $result;
        }

        // order on monday/thursday
        $addDays = $today->format('N') === '1' ? 3 : 4;
        $nextOrderDay = $today->modify(sprintf('+%d days', $addDays));

        return array_merge(
            $result,
            $this->simulateOrdering(
                $io,
                $nextOrderDay,
                $today,
                $stock,
                $demand,
                $vendorOrders,
                $inventory,
                $iteration + 1
            )
        );
    }

    /** @return ReorderPoint[] */
    private function getROPs(
        SymfonyStyle $io,
        DateTimeImmutable $today,
        array $inventory,
        int $leadDaysAdjustment
    ): array {
        if (!$this->useForecastInsteadDemand) {
            $this->demandCalculator->initDemand($today, 1);
        }

        $this->variantGroupCalculator->calculateVariantGroups($today, 1);

        $rops = [];
        $io->progressStart(count($inventory));
        foreach ($inventory as $item) {
            $io->progressAdvance();
            if (empty($item['variant_id'])) {
                continue;
            }

//            $group = $this->variantGroupCalculator->getVolumeGroup($item['variant_id']);
//            if (!in_array($group, ['A+', 'A'])) {
//                continue;
//            }

            $rops[] = $this->ropCalculator->calculate(
                $item['variant_id'],
                $item['product_id'],
                $today,
                $leadDaysAdjustment,
                $this->useForecastInsteadDemand
            );
        }
        $io->progressFinish();
        return $rops;
    }

    private function getDemandBetween(array $demand, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_filter(
            $demand,
            fn ($item) => $item['order_created_at'] >= $from->format('Y-m-d H:i:s')
                && $item['order_created_at'] < $to->format('Y-m-d H:i:s')
        );
    }

    private function getSimulationInventory(array $stock, array $demand): array
    {
        $inventory = [];
        foreach ($stock as $item) {
            $inventory[$item['variant_id']] = [
                'variant_id' => $item['variant_id'],
                'product_id' => $item['product_id']
            ];
        }

        foreach ($demand as $item) {
            if (!isset($inventory[$item['variant_id']])) {
                $inventory[$item['variant_id']] = [
                    'variant_id' => $item['variant_id'],
                    'product_id' => $item['product_id']
                ];
            }
        }

        $inventory = array_filter($inventory, fn ($item) => !empty($item['variant_id']));

        if ($this->useForecastInsteadDemand) {
            return array_filter(
                $inventory,
                fn ($item) => $this->forecastDemandCalculator->hasDemand($item['variant_id'])
            );
        }

        $excludedProducts = [
            'SER-FIT-STUDIO',
            'SER-CON',
            'SER-CON-0-60',
            'SER-REFIT-STUDIO',
            'SER-FSS-STUDIO',
            'SER-REFIT',
            'SER-CON-STUDIO',
            'SER-REFIT-M-90-180',
        ];

        $variants = $this->sosRepository->getItems('242db0d8-8f8e-4a10-a78b-1deebd04c93b');

        return array_filter(
            $inventory,
            fn ($item) => in_array($item['variant_id'], $variants, true)
                && !in_array($item['product_id'], $excludedProducts, true)
        );
    }

    private function getStock(DateTimeImmutable $startDate): array
    {
        $stock = $this->logisticsRepository->getStock($startDate);
        $return = [];
        foreach ($stock as $item) {
            $return[$item['variant_id']] = $item;
        }

        return $return;
    }

    private function getVendorOrders(DateTimeImmutable $startDate): array
    {
        $items = $this->vomRepository->getVendorOrderItems($startDate->modify('-1 month'), $startDate);

        $orders = [];
        foreach ($items as $item) {
            $orders[$item['variant_id']] ??= [];
            $orders[$item['variant_id']][] = [
                'variant_id' => $item['variant_id'],
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'lead' => $item['updated_at'],
                'created' => $item['created_at'],
            ];
        }

        return $orders;
    }
}
