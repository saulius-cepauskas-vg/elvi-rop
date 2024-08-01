<?php

declare(strict_types=1);

namespace App\Cli;

use App\Repository\SoRepository;
use App\Service\ReorderPointCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'simulation:so')]
class SimulationSOCli extends Command
{
    use OutputTrait;

    private string $id;

    public function __construct(
        private SoRepository $soRepository,
        private ReorderPointCalculator $calculator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // configure, add argument for id
        $this->addArgument('id', InputArgument::REQUIRED, 'Stock order id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->id = $input->getArgument('id');

        $this->so($this->id, $io);

        return Command::SUCCESS;
    }

    private function so(string $stockOrderId, SymfonyStyle $io): void
    {
        $io->title($stockOrderId);

        $items = $this->soRepository->getItems($stockOrderId);


        $data = [];
        $totals = ['rows' => 0, 'so_qty_sum' => 0, 'rop_sum' => 0];
        foreach ($items as $item) {
            $rop = $this->calculator->calculate($item['variant_id'], $item['product_id']);

            if ($rop->lead->leadTimeStandardDeviation > 10) {
                $io->warning(
                    sprintf(
                        'Lead time std deviation is too high for product %s %s',
                        $item['product_id'],
                        $item['variant_id']
                    )
                );
                continue;
            }

            $order = $rop->rop >= (int)$item['combined_stock'] && $rop->rop > 0;
            $ropOrderQty = $order ? $rop->rop - $item['combined_stock'] : 0;

            $data[] = [
                'variant_id' => $item['variant_id'],
                'product_id' => $item['product_id'],
                'sos_target' => $item['adjusted_target_quantity'],
                'so_qty' => $item['quantity'],
                'stock' => $item['combined_stock'],
                'rop' => $rop->rop,
                'rop_order_qty' => $ropOrderQty,
                'order' => $order ? 'Yes' : 'No',
                'security' => $rop->securityStock,
                'D_avg_day' => round($rop->demand->demandAveragePerDay, 2),
                'D_std' => round($rop->demand->demandStandardDeviation, 2),
                'D_monthly' => implode(',', $rop->demand->monthlyDemand),
                'L_avg_day' => round($rop->lead->averageLeadTimeInDays, 2),
                'L_std' => round($rop->lead->leadTimeStandardDeviation, 2),
                'L_type' => $rop->lead->leadType,
                'L_records_count' => $rop->lead->leadTimeRecordsCount,
                'Group' => $rop->group,
                'Z Coefficient' => $rop->zCoefficient,
            ];

            $totals['so_qty_sum'] += $item['quantity'];
            if ($order) {
                $totals['rop_sum'] += $ropOrderQty;
            }
        }

        $totals['rows'] = count($items);

        usort($data, function ($a, $b) {
            if ($a['order'] === $b['order']) {
                return $a['rop'] <=> $b['rop'];
            }

            return $a['order'] <=> $b['order'];
        });

        $this->display($io, $data, $totals);
        $this->csv($data, sprintf('so_groups_and_rop_%s', $this->id));
    }
}
