<?php

declare(strict_types=1);

namespace App\Cli;

use App\Dto\ReorderPoint;
use App\Repository\LogisticsRepository;
use App\Repository\Pm2Repository;
use App\Repository\VomRepository;
use App\Service\DataTrait;
use App\Service\DemandCalculator;
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

    public function __construct(
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
        $startDate = new DateTimeImmutable('2023-01-02');
//        $startDate = new DateTimeImmutable('2024-02-05');

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
                $diff = $rop->adjustedRop - ($leadDaysAdjustment * $rop->demand->demandAveragePerDay) - $variantStock - $variantInVendorOrders + (($rop->lead->averageLeadTimeInDays + $leadDaysAdjustment) * $rop->demand->demandAveragePerDay);
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

//        if ($iteration > 40) {
            if ($iteration > 144) {
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
        $this->demandCalculator->initDemand($today, 1);
        $this->variantGroupCalculator->calculateVariantGroups($today, 1);

        $rops = [];
        $io->progressStart(count($inventory));
        foreach ($inventory as $item) {
            $io->progressAdvance();
            if (empty($item['variant_id'])) {
                continue;
            }

            $group = $this->variantGroupCalculator->getVolumeGroup($item['variant_id']);
            if (!in_array($group, ['A+', 'A'])) {
                continue;
            }

            $rops[] = $this->ropCalculator->calculate($item['variant_id'], $item['product_id'], $leadDaysAdjustment);
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
        // todo: remove me
//        return [
//
//            'b6612b319c02ebfb91099c615514cf27' => [
//                'variant_id' => 'b6612b319c02ebfb91099c615514cf27',
//                'product_id' => 'AOS-1' // AOSEPT PLUS - 360ml
//            ],
//            '762e6ae16e01832364adbe6181fb5ea1' => [
//                'variant_id' => '762e6ae16e01832364adbe6181fb5ea1',
//                'product_id' => 'AOSH-1' // AOSEPT PLUS with HydraGlyde - 360ml
//            ],
//            'ffee2040f6ddc6bf3d0cf0b0c5946b4f' => [
//                'variant_id' => 'ffee2040f6ddc6bf3d0cf0b0c5946b4f',
//                'product_id' => 'BIOT-2' // Biotrue All in one - 2x300ml inkl. Behälter
//            ],
//            '584d957128cd8cabac61debe47c9c153' => [
//                'variant_id' => '584d957128cd8cabac61debe47c9c153',
//                'product_id' => 'ES-3' // EasySept 3Pack - 3x360ml
//            ],
//            '1227f82a942f98f9fb0056207f7ca3f0' => [
//                'variant_id' => '1227f82a942f98f9fb0056207f7ca3f0',
//                'product_id' => 'BIOT-1' // Biotrue - 300ml
//            ],
//            '9792ba97048512e5c9111de1a02e6311' => [
//                'variant_id' => '9792ba97048512e5c9111de1a02e6311',
//                'product_id' => 'OFP-2-1' // Opti-Free Puremoist 2x300ml plus 90ml
//            ],
//            '8faff017491066d124687edf4f6aaa4e' => [
//                'variant_id' => '8faff017491066d124687edf4f6aaa4e',
//                'product_id' => 'DLAH-1' // DLENS All in One mit Hyaluron - 360ml inkl. Behälter
//            ],
//
//
//            '6c768942b4c0041b48944342a92e23a7' => [
//                'variant_id' => '6c768942b4c0041b48944342a92e23a7',
//                'product_id' => 'DACP-90' // DAILIES AquaComfort PLUS 90
//            ],
//            'acca0c13091b0353faa2c68e12505086' => [
//                'variant_id' => 'acca0c13091b0353faa2c68e12505086',
//                'product_id' => 'DACP-90' // DAILIES AquaComfort PLUS 90
//            ],
//            '69785474ce56d82771d8fd2d3124a6fe' => [
//                'variant_id' => '69785474ce56d82771d8fd2d3124a6fe',
//                'product_id' => 'DACP-90' // DAILIES AquaComfort PLUS 90
//            ],
//
//            '4acb925edfdd90cfad9be342a5b266a3' => [
//                'variant_id' => '4acb925edfdd90cfad9be342a5b266a3',
//                'product_id' => 'AO-6' //Acuvue Oasys - 6 Kontaktlinsen
//            ],
//            '32f0bcf01b3ec235f2178b560440b753' => [
//                'variant_id' => '32f0bcf01b3ec235f2178b560440b753',
//                'product_id' => 'DT1-90' // DAILIES TOTAL 1 - 90
//            ],
//            '373609fab950b44b2abaa0ff2509839b' => [
//                'variant_id' => '373609fab950b44b2abaa0ff2509839b',
//                'product_id' => 'DT1-90' // DAILIES TOTAL 1 - 90
//            ],
//            '4d19c26354c9524874449b7374db2456' => [
//                'variant_id' => '4d19c26354c9524874449b7374db2456',
//                'product_id' => 'DT1-90' // DAILIES TOTAL 1 - 90
//            ],
//        ];

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

        // all included
        $ids = [
            'BFT-6',
            'BFM-3',
            'AO1DA-30',
            'DT1M-90',
            'BFT-3',
            'AO-12',
            'AOC-2',
            'SB-2112',
            'LBMFR61A',
            'AO1DA-90',
            'A2-6',
            '1AMA-30',
            'DACPT-30',
            'BFM-6',
            'AOHM-6',
            'AOFA-6',
            'AOH-3',
            'BTP-30',
            '1AM-90',
            'AO1DM-30',
            'LBBLF51',
            'DACPT-90',
            'MDM-30',
            'PRS1-90',
            'PD-90',
            '1AMM-90',
            '1AM-30',
            'SL59-6',
            'AOHA-6',
            'REMP-2',
            'AOHM-3',
            'PM-6',
            'BMD-90',
            'LBBOX66B',
            'AO1DMM-5',
            'ACV-6',
            'DT1A-30',
            'PV-6',
            'FR1DC-10',
            'AO-6',
            'BF-6',
            'DACP-90',
            'DACP-30',
            'PVM-6',
            'MDM-90',
            'ACVA-6',
            'LBBLF52',
            'DT1M-5',
            'DT1M-30',
            '1AMM-30',
            'BMD-30',
            'PRS1-30',
            'AO1DMM-30',
            'DADC-90',
            'PRS1A-90',
            'MD-90',
            'U-6',
            'SLD-90',
            'DT1-5',
            'UOD-30',
            'BM55E-6',
            'DSAB-6',
            'DACPM-30',
            'DT1-30',
            'ISO-30',
            'PDM-30',
            'SLD-30',
            '1AMA-90',
            'AOH-6',
            'AOND-6',
            'PRS1A-30',
            'TO30-6',
            'BF-3',
            'FRCB-2',
            'PV2-3',
            'BMT-6',
            'BF-1',
            'PV2-6',
            'DACPM-90',
            'CD-90',
            'CD-30',
            'BIOGA',
            'SB-10043',
            'MD-30',
            'PV2P-3',
            'UP-6',
            'SB-2908',
            'LBHMR62H',
            'AV-6',
            'DADC-30',
            'EW142-6',
            'DT1-90',
            'AV-3',
            'PS-6',
            'AO1D-90',
            'SH-ED',
//            'SER-FIT-STUDIO',
            'CDT-30',
            'LBHBLF73',
            'BTA-30',
            'DT1A-5',
            'MDT-30',
            'CE-6',
            'ACCAC-1',
            'BT-90',
            'SB-2981',
            'BFE-3',
            'AVT-6',
            'LBMR59',
            'DMV-MS-3',
            'SLM-6',
            'EX136-6',
            'DSP-30',
            'AO1D-30',
            'TO30M-6',
            'PD-30',
            'AOM-6',
            'SLA-6',
            'SOC-3',
            'PV2P-6',
            'DSSL-6',
            'AOA-6',
            'AO1D-5',
            'BT-30',
            'CDM-30',
            'SB-10016',
            'LBHMR56E',
            'LBHMR65C',
            'DT1A-90',
            'LBMR52E',
            'LBHMR55F',
            'MD-5',
            'LBBOX66S',
            'AO1DA-5',
            'LBOO',
            'LBHBLF73C',
            'AO1DM-5',
            'AO1DMM-90',
            'FMS-100',
            'NO2',
            'MPIN',
            'PCO-1',
            'PS-3',
            'BPT-ED',
            'OFP-2-1',
            'BTP-90',
            'BFE-6',
            'PV2A-6',
            'SB-10063',
            'STAS-1',
            'FLA',
            'LBHRM56D',
            'LBHMR62D',
            'LBBLF51A',
            'AVT-3',
            'LBMR51',
            'MID-30',
            'UOD-90',
            'BIOT-1',
            'LBBLF51F',
            'AOHA-3',
            'LBHMR64A',
            'BSD-30',
            'LNAT-P',
            'BFXR-6',
            'SLNC-2',
            'PM-3',
            'LBMR61',
            'UA-6',
            'U-3',
            'AOH-1',
            'LBHMR55E',
            'UP-3',
            'LBHMR55',
            'CDM-5',
            'CLEARS-125ml',
            'CPTO-3',
            'SB-26',
            'SB-2917',
            'LBBLF52F',
            'AV-S',
            'BMDT-30',
            'AOA-3',
            'HYLO-CO',
            'ICOA-250ml',
            'SB-2978',
            'SL38-6',
            'LLRVC2',
            'LBF',
            'OCSI-10',
            'PUS-1',
            'OCS-10',
            'LBHMR65A',
            'LBHRM56F',
            'PTXR-6',
            'HMR56C',
            'RB-2251',
            'BIOA-60',
            'ICOA-100ml',
            'SAM-100ml',
            'SB-2999',
            'LBHMR55A',
            'AOM-3',
            'OCP-5',
            'HYLO-CA',
            'LP-360ml',
            'PRS1-5',
            'FPA-1',
            'HYLO-DU',
            'OCI-10',
            'LBHMR56',
            'LBHMR83C',
            'LBMR52',
            'READ-1',
            'LBHMR83',
            'SB-2930',
            'SB-2954',
            'EEH-15ml',
            'FMS-1',
            'AV-GPM-1',
            'FDP-90',
            'MCPU-2',
            'LBBOX66AS',
            'SB-2919',
            'BIOA',
            'ES-1',
            'LBHMR55C',
            'EB809-2',
            'VMA-F',
            'ICL-R30',
            'SB-2928',
            'AV-GPM-120',
            'NO1',
            'SB-2433',
            'AV-ET-10',
            'EEM-1',
            'EVO',
            'SB-2901',
            'SB-2921',
            'RB-2278',
            'LBBLF52A',
//            'SER-CON',
            'AOS-1',
            'AOSH-1',
            'CLEARS-50ml',
            'HYLO-DUI',
            'FPS-30',
            'LNATG-F',
            'LBMR64E',
            'STA-1',
            'LBBOX66A',
            'DLTB-1',
            'SB-2971',
            'CSP-6',
            'SB-1084',
            'SB-2961',
            'CPR-30',
            'SB-3010',
            'PUSO-100',
            'OFR-2',
            'OFP-FP',
            'TCLR-30',
            'SAM-360ml',
            'ES-S',
            'ALL-C',
            'LBHMR65',
            'CDSL-TK',
            'BIT-MD',
            'LBMR60B',
            'ARL-100ml',
            'LBHBLF73A',
            'SB-3021',
            'MCPU-4',
            'DSD-30',
            'VPK',
            'LNAT-A',
            'LBHMR62A',
            'SB-2974',
            'OFR-1',
            'RCB-15',
            'AO-6-P',
            'PC1D-30',
            'LBHMR65E',
            'LBMR51F',
            'FD-90',
            'REMP-100',
            'VGMA-F',
            'US-2',
            'ECP-1',
            'FS2A-N',
            'SB-2951',
            'OFP-90ml',
            'SOC-250ml',
            'PUSOLB-1',
            '1AMM-5',
            'US-TK',
            'ACCA-3',
            'EEM-120ml',
            'F7D-12',
            'CDS-TK',
            'LBMR65D',
            'ARL-2',
            'LCA-1',
            'SSP311B',
            'CDGP-TK',
            'TO30-1',
            'MCPU-1',
            'NBIC-1',
            'SB-2936',
            'SB-2968',
            'CPTOB',
            'LNAS',
            'SU-MD',
            'LBMR60',
            'SB-755',
            'ILLP-60',
            'LTB-1',
            'BAR',
            'LFNPC2',
            'CPS-100ml',
            'SB-724',
            'DLAH-60',
            'BFTXR-6',
            'LBMR65A',
            'BIOT-ED',
            'LBHMR73B',
            'REMPS-6',
            'OLX-10',
            'SB-2976',
            'SCPF-MD',
            'SB-2958',
            'CDSLI-2',
            'ACCOS-3',
            'SB-779',
            'BSAIO-2',
            'OCBP-28',
            'SB-2899',
            'SB-10053',
            'BFM-1',
            'CPS-250ml',
            'BSC-6',
            'AOHM-1',
//            'SER-CON-0-60',
            'FPA-2',
            'BAA',
            'AV-AL-1',
            'BUEV-100',
//            'SER-REFIT-STUDIO',
            'DMV-SH-3',
            'DSA-30',
            'LBHBLF73B',
            'VLA-F',
            'LNL',
            'VA-ED-60',
            'SB-2996',
            'CPTO-1',
            'LBHMR65B',
            'LBBLF51B',
            'ICP-10',
            'MH',
            'SSP313B',
            'REG-2',
            'SB-1946',
            'PUS-100',
            'BSAIO-100',
            'DLLB-1',
            'ACCA-60',
//            'SER-FSS-STUDIO',
            'VGA-ED-60',
            'DLAH-1',
            'LBMR51D',
            'MPSP',
            'DLFA-ED',
            'ACCA-1',
            'HYLO-GE-2',
            'SOC-2',
            'ACCOS-60',
            'SB-2984',
            'AOSH-FP',
            'LNATG-A',
            'LBMR52C',
//            'SER-REFIT',
            'BCA-100ml',
            'SUPF-MD',
            'LBMR64',
            'LNAT-F',
            'SB-618',
            'SB-62',
            'BPT-MD',
            'EEM-720ml',
            'FLR',
            'PSB-1',
//            'SER-CON-STUDIO',
            'SB-2939',
            'IREL-M',
            'SB-3004',
            'SB-2922',
            'PUSO-1',
            'BSA',
            'EEH-MD',
            'SB-10056',
            'REMP-1',
            'LBHMR62F',
            'LBMR61A',
            'REMP-4',
            'SB-10057',
            'OFP-1',
            'MCP',
            'LSZC2',
            'REMPS-2-1',
            'MSPC-1',
            'SB-2927',
            'SSP311A',
            'BC-MD',
            'LBFE',
            'LNWB',
            'LBMR52A',
            'OGH-50',
            'VPA',
            'VGA-ED',
            'FGP-200',
            'BIOT-S',
            'US',
            'SLW',
            'SB-2900',
            'BSFP',
            'LNLP',
            'BSAIO-1',
            'FBTM-30',
            'CS-MD',
            'REG-100',
            'SB-2969',
            'SOC-1',
            'TO30A-6',
            'FPB',
            'LBMR53',
            'LBHMR64',
            'LBMR52B',
            'ECSL-1',
            'LBFO',
            'FS1-D',
            'REG-1',
            'SB-749',
            'NBBBT',
            'ACCF-1',
            'BS-90',
            'ES-3',
            'SEK',
            'SB-2983',
            'OXC-100ml',
            'PT-6',
            'SB-2987',
            'LH45DMV-3',
            'DMV-ULT3',
            'ILLH-60',
            'READ-3',
            'VA-ED',
            'AOSH-S',
            'UOD-5',
            'AV-AL-2',
            'SHPF-MD',
            'RRGP-1',
            'EES-360ml',
            'LIPC3',
            'SSP307',
            'SU-3ED',
            'CDS-1',
            'SSP313F',
            'SOC-100ml',
            'CDGP-1',
            '1AM-10',
            'IDCP-20',
            'OCSA-17',
            'EES-100ml',
            'BIOT-2',
            'EEA-2',
            'FD-30',
            'OX-3',
            'NBD-1',
            'LBMR65',
            'PIN',
            'LIPC1',
            'CM-6',
            'OXC-360ml',
            'ACCOS-1',
            'SSP313',
            'LP-240ml',
            'PRR-50',
            'SB-2519',
            'TCTP-1',
            'LP-120ml',
            'CPK',
//            'SER-REFIT-M-90-180',
            'SB-761',
            'EEA-100ml',
            'CS-ED',
            'ACCE-1',
            'ACSF-1',
            'BSM-6',
            'ECP-2',
            'SB-1086',
            'AOSH-2',
            'BITP-MD',
            'HYLO-GE',
            'SB-2924',
            'FGP-100',
            'ACCFR-1',
            'BNC',
            'MLB',
            'BIOT-MD',
            'BIOT-100',
            'EEA-1',
            'TCR',
            'SB-10052',
            'BCA-2',
            'CDSL-1',
            'ARL-1',
            'SB-2962',
            'SB-2992',
            'FPA-100ml',
            'HYLO-FR',
            'FMS-2',
            'SB-2977',
            'TCA',
            'REMPS-1',
            'HYLO-CO-2',
            'SSP313C',
            'SB-2942',
            'SB-10044',
            'BAM-1',
            'DMV-MT-3',
            'DACP-5',
            'RB-2236',
            'OFE-1',
            'LBMR51B',
            'SB-3000',
            'BIT-ED'
        ];

        return array_filter($inventory, fn ($item) => in_array($item['product_id'], $ids, true));

        return $inventory;
    }

    private function getStock(DateTimeImmutable $startDate): array
    {
        return [];
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
