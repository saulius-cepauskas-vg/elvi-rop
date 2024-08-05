<?php

namespace App\Cli;

use Symfony\Component\Console\Style\SymfonyStyle;

trait OutputTrait
{
    protected function display(SymfonyStyle $io, array $data, ?array $totals = null): void
    {
        if (count($data) === 0) {
            $io->warning('No data to display');
            return;
        }

        $io->table(array_keys(current($data)), $data);

        $table = [];
        foreach ($totals ?? [] as $key => $value) {
            $table[] = [$key, $value];
        }

        $io->table([], $table);
    }

    protected function csv(array $data, string $name, bool $putHeader = true, string $mode = 'w'): void
    {
        $fp = fopen(sprintf('var/%s.csv', $name), $mode);

        if ($putHeader) {
            fputcsv($fp, array_keys(current($data)));
        }

        foreach ($data as $fields) {
            fputcsv($fp, $fields);
        }

        fclose($fp);
    }
}