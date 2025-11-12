<?php
namespace App\Repositories;

interface ProductRepositoryInterface
{
public function createProductWithVariations(array $data, string $shopUrl, string $accessToken): array;
}
