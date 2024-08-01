<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

class VomRepository
{
    public function __construct(private Connection $vomConnection)
    {
    }

    public function getLeadTimeItems(): array
    {
        return $this->vomConnection->fetchAllAssociative(
            <<<sql
                SELECT
                    variant_id,
                    created_at,
                    updated_at,
                    recipient,
                    quantity,
                    product_id,
                    vendor_company as vendor,
                    DATEDIFF(updated_at, created_at) as days
                FROM
                    vendor_item
                WHERE
                    recipient = "warehouse"
                    AND status = "delivered"
                    AND year(created_at) >= 2022
            sql
        );
    }

    public function getVendorOrderItems(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->vomConnection->fetchAllAssociative(
            <<<sql
                SELECT
                    variant_id,
                    created_at,
                    updated_at,
                    recipient,
                    quantity,
                    product_id,
                    vendor_company as vendor,
                    DATEDIFF(updated_at, created_at) as days
                FROM
                    vendor_item
                WHERE
                    recipient = "warehouse"
                    AND status = "delivered"
                    AND created_at BETWEEN ? AND ?
                    AND updated_at > ?
            sql,
            [
                $from->format('Y-m-d 00:00:00'),
                $to->format('Y-m-d 23:59:59'),
                $to->format('Y-m-d 23:59:59'),
            ]
        );
    }
}
