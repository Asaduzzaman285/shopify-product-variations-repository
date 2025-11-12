<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShopifyProductRequest;
use App\Repositories\ProductRepositoryInterface;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    protected ProductRepositoryInterface $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

   public function store(StoreShopifyProductRequest $request, ProductRepositoryInterface $repo)
{
    $shopUrl = $request->header('X-Shopify-Shop-Domain');
    $accessToken = $request->header('X-Shopify-Access-Token');

    if (!$shopUrl || !$accessToken) {
        return response()->json(['message' => 'Missing Shopify shop domain or access token'], 400);
    }

    $result = $repo->createProductWithVariations($request->validated(), $shopUrl, $accessToken);

    if ($result['success']) {
        return response()->json(['message' => $result['message']], 201);
    }

    return response()->json(['message' => $result['message'], 'errors' => $result['errors'] ?? $result['error'] ?? null], 500);
}

}
