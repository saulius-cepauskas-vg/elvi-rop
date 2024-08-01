<?php

declare(strict_types=1);

namespace App\Cli;

use App\Repository\DwhRepository;
use App\Service\DataTrait;
use App\Service\LeadCalculator;
use App\Service\VariantGroupCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(name: 'simulation:demand')]
class SimulationDemandCli extends Command
{
    use DataTrait;
    use OutputTrait;

    public function __construct(
        private DwhRepository $dwhRepository,
        private LeadCalculator $leadCalculator,
        private VariantGroupCalculator $variantGroupCalculator,
        private CacheInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $data = [];
        $io->progressStart(count($this->getDemand()));
        foreach ($this->getDemand() as $item) {
            $io->progressAdvance();

            if (isset($data[$item['variant_id']])) {
                continue;
            }

            if (empty($item['variant_id'])) {
                continue;
            }

            $lead = $this->leadCalculator->getLead(
                $item['product_id'],
                $item['variant_id'],
                $this->variantGroupCalculator->getGroupVariantIds($item['variant_id'])
            );

            $data[$item['variant_id']] = [
                'volume' => $this->variantGroupCalculator->getVolumeGroup($item['variant_id']),
                'variation' => $this->variantGroupCalculator->getVariationGroup($item['variant_id']),
                'variant_id' => $item['variant_id'],
                'product_id' => $item['product_id'],
                'avg_lead_time' => round($lead->averageLeadTimeInDays, 2),
                'lead_time_std' => round($lead->leadTimeStandardDeviation, 2),
                'lead_type' => $lead->leadType,
                'lead_records_count' => $lead->leadTimeRecordsCount,
            ];
        }
        $io->progressFinish();

        $this->display($io, $data);
        $this->csv($data, sprintf('demand_groups_and_rop_%s', date('Ymdhis')));

        print_r($this->variantGroupCalculator->getStats());

        return Command::SUCCESS;
    }
}
