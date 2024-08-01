<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class LogisticsRepository
{
    public function __construct(private Connection $logisticsConnection)
    {
    }

    public function getStock(\DateTimeInterface $date): array
    {
        return $this->logisticsConnection->fetchAllAssociative(
            <<<sql
                SELECT
                    sum(stock_level) stock,
                    item_nr_int variant_id,
                    item_nr_suppl product_id
                FROM
                    stock_history
                WHERE
                    stock_date BETWEEN ? AND ?
                GROUP BY
                    item_nr_int, item_nr_suppl; 
            sql,
            [
                $date->format('Y-m-d 00:00:00'),
                $date->format('Y-m-d 23:59:59'),
            ]
        );
    }
}
