<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class SosRepository
{
    public function __construct(private Connection $sosConnection)
    {
    }

    public function getItems(string $history): array
    {
        return $this->sosConnection->fetchFirstColumn(
            <<<sql
                SELECT
                    json_unquote(json_extract(original_contents, '$.variant_id')) as variant_id
                FROM
                    stock_order_suggestion
                WHERE
                    history_id = ?
            sql,
            [$history]
        );
    }
}
