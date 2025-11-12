<?php

namespace App\Repositories;

use GuzzleHttp\Client;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductRepository implements ProductRepositoryInterface
{
    /**
     * Create product with variants in a single mutation and save locally.
     *
     * @param array $data
     * @param string $shopUrl
     * @param string $accessToken
     * @return array
     */
    public function createProductWithVariations(array $data, string $shopUrl, string $accessToken): array
    {
        $client = new Client([
            'base_uri' => "https://{$shopUrl}/admin/api/2025-07/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $mutation = $this->buildProductWithVariantsMutation($data);

        try {
            $response = $client->post('', [
                'json' => ['query' => $mutation],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (isset($body['errors'])) {
                Log::error('Shopify API errors', $body['errors']);
                return ['success' => false, 'message' => 'Shopify API error', 'errors' => $body['errors']];
            }

            $productCreate = $body['data']['productCreate'] ?? null;
            $productData = $productCreate['product'] ?? null;
            $userErrors = $productCreate['userErrors'] ?? [];

            if (!$productData) {
                Log::error('Shopify product creation failed', $userErrors);
                return ['success' => false, 'message' => 'Product creation failed on Shopify', 'errors' => $userErrors];
            }

            // Save product locally
            $product = Product::create([
                'shopify_product_id' => $productData['id'],
                'title' => $productData['title'],
                'description' => $data['description'] ?? null,
            ]);

            // Save variants locally
            foreach ($productData['variants']['edges'] as $variantEdge) {
                $variant = $variantEdge['node'];
                $product->variations()->create([
                    'shopify_variant_id' => $variant['id'],
                    'title' => $variant['title'],
                    'price' => $variant['price'],
                    'inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                ]);
            }

            return [
                'success' => true,
                'message' => 'Product and variants created successfully',
                'product' => $product,
            ];
        } catch (\Exception $e) {
            Log::error('Shopify API request failed', ['exception' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Shopify API request failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Build Shopify productCreate mutation with variants included.
     *
     * @param array $data
     * @return string
     */
    protected function buildProductWithVariantsMutation(array $data): string
    {
        $title = addslashes($data['title']);
        $description = addslashes($data['description'] ?? '');

        $variantsInput = [];
        if (!empty($data['variations'])) {
            foreach ($data['variations'] as $variant) {
                $vTitle = addslashes($variant['title']);
                $vPrice = $variant['price'];
                $vInventory = $variant['inventory_quantity'] ?? 0;

                $variantsInput[] = <<<VARIANT
{
  title: "{$vTitle}",
  price: "{$vPrice}",
  inventoryQuantity: {$vInventory}
}
VARIANT;
            }
        }
        $variantsString = implode(", ", $variantsInput);

        return <<<GQL
mutation {
  productCreate(input: {
    title: "{$title}",
    descriptionHtml: "{$description}",
    variants: [{$variantsString}]
  }) {
    product {
      id
      title
      variants(first: 10) {
        edges {
          node {
            id
            title
            price
            inventoryQuantity
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }
}
