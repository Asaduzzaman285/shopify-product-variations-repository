<?php

namespace App\Repositories;

interface ProductRepositoryInterface
{
    /**
     * Create a Shopify product with its variations (e.g., color, size).
     *
     * @param array $data          Product + variation details
     * @param string $shopUrl      Shopify store URL
     * @param string $accessToken  Shopify private app access token
     * @param string $locationId   Shopify location ID
     * @return array               Result data (success, message, data, errors)
     */
    public function createProductWithVariations(array $data, string $shopUrl, string $accessToken, string $locationId): array;
}
