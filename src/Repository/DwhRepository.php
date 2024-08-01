<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class DwhRepository
{
    public function __construct(private Connection $dwhConnection)
    {
    }

    public function getItems(): array
    {
        return $this->dwhConnection->fetchAllAssociative(
            <<<sql
                SELECT
                    order_item_id,
                    order_created_at,
                    variant_id,
                    product_id,
                    quantity
                FROM
                    dwh_order_item
                WHERE
                    order_active = 1
                    AND cohort_order_created_at_year > 2022;
            sql
        );
    }
}
