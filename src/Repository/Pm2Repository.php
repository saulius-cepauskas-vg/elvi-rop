<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

class Pm2Repository
{
    public function __construct(private Connection $pm2Connection)
    {
    }

    public function getProductVendorMap(): array
    {
        $result = [];
        foreach ($this->getProductVendor() as $productVendor) {
            $result[$productVendor['product_id']] = $productVendor['vendor_id'];
        }

        return $result;
    }

    private function getProductVendor(): array
    {
        return $this->pm2Connection->fetchAllAssociative(
            <<<sql
                SELECT
                    product.code as product_id,
                    vendor.pixi_code as vendor_id
                FROM
                    elvi_price_manager_v2.vendor_price price,
                    elvi_price_manager_v2.product product,
                    elvi_price_manager_v2.vendor vendor
                WHERE
                    price.amount IS NOT NULL
                    AND product.id = price.product_id
                    AND vendor.id = price.vendor_id
            sql
        );
    }
}
