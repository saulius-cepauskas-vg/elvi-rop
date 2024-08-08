<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class SosRepository
{
    public function __construct(private Connection $sosConnection)
    {
    }

    public function getDefaultItems(): array
    {
        return $this->getItems('242db0d8-8f8e-4a10-a78b-1deebd04c93b');
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
