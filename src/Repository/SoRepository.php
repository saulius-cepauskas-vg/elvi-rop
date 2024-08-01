<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class SoRepository
{
    public function __construct(private Connection $soConnection)
    {
    }

    public function getItems(string $stockOrderId): array
    {
        return $this->soConnection->fetchAllAssociative(
            <<<sql
                SELECT
                    variant_id,
                    json_unquote(json_extract(contents, '$.variant_representation.product_id')) as product_id,
                    json_unquote(json_extract(contents, '$.quantity')) as quantity,
                    json_unquote(json_extract(meta, '$.target_quantity')) as target_quantity,
                    json_unquote(json_extract(meta, '$.adjusted_to_stock_order_days_amount')) as adjusted_target_quantity,
                    json_unquote(json_extract(meta, '$.combined_stock')) as combined_stock
                FROM
                    stock_order_item
                WHERE
                    stock_order_id = ?
                -- LIMIT 20
            sql,
            [$stockOrderId]
        );
    }
}
