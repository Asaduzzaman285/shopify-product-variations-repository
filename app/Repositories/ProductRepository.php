<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\Variation;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Class ProductRepository
 *
 * Implements ProductRepositoryInterface to create Shopify products with options and variants.
 *
 * @package App\Repositories
 */
class ProductRepository implements ProductRepositoryInterface
{
    /**
     * Create a Shopify product with options and variants, and save locally (optional).
     *
     * @param array  $data        Request validated payload
     * @param string $shopUrl     Shopify shop domain (e.g. myshop.myshopify.com)
     * @param string $accessToken Shopify admin access token (X-Shopify-Access-Token)
     * @param string $locationId  Shopify location GID (for inventoryQuantities)
     *
     * @return array
     */
    public function createProductWithVariations(array $data, string $shopUrl, string $accessToken, string $locationId): array
    {
        $apiVersion = config('shopify.api_version', env('SHOPIFY_API_VERSION', '2025-07'));

        $client = new Client([
            'base_uri' => "https://{$shopUrl}/admin/api/{$apiVersion}/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);

        try {
            // Ensure unique title to avoid accidental duplicates
            $originalTitle = $data['title'] ?? 'Untitled Product';
            $uniqueTitle = $originalTitle . ' ' . now()->format('YmdHis');
            $data['title'] = $uniqueTitle;

            // Extract options FIRST before creating the product
            $options = $this->extractProductOptions($data['variations'] ?? []);

            // Step 1: Create product with productOptions
            $productCreateQuery = $this->buildProductCreateWithOptionsMutation($data, $options);
            Log::info('Step 1: Creating product with options');

            $response = $client->post('', ['json' => ['query' => $productCreateQuery]]);
            $body = json_decode((string) $response->getBody(), true);

            if (!empty($body['errors']) || !empty($body['data']['productCreate']['userErrors'])) {
                return [
                    'success' => false,
                    'message' => 'Shopify product creation failed',
                    'data' => null,
                    'errors' => $body['errors'] ?? $body['data']['productCreate']['userErrors'] ?? null,
                ];
            }

            $shopifyProduct = $body['data']['productCreate']['product'] ?? null;
            if (!$shopifyProduct || empty($shopifyProduct['id'])) {
                return [
                    'success' => false,
                    'message' => 'Shopify product creation failed: no product returned',
                    'data' => $body,
                ];
            }

            $shopifyProductId = $shopifyProduct['id'];
            $productOptions = $shopifyProduct['options'] ?? [];
            
            Log::info('Product created', [
                'productId' => $shopifyProductId,
                'options' => $productOptions
            ]);

            // Step 2: Create all variant combinations using productVariantsBulkCreate
            $variantInputs = $this->buildVariantInputs($data['variations'] ?? [], $productOptions, $locationId);
            
            if (!empty($variantInputs)) {
                $bulkCreateMutation = $this->buildProductVariantsBulkCreateMutation($shopifyProductId, $variantInputs);
                
                Log::info('Step 2: Creating variants in bulk', [
                    'variant_count' => count($variantInputs),
                    'locationId' => $locationId
                ]);

                $variantResponse = $client->post('', ['json' => ['query' => $bulkCreateMutation]]);
                $variantBody = json_decode((string) $variantResponse->getBody(), true);
                
                Log::info('Variant creation response', ['response' => $variantBody]);

                if (!empty($variantBody['errors']) || !empty($variantBody['data']['productVariantsBulkCreate']['userErrors'])) {
                    Log::error('Variant creation failed', [
                        'errors' => $variantBody['data']['productVariantsBulkCreate']['userErrors'] ?? $variantBody['errors']
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Failed to create product variants',
                        'data' => null,
                        'errors' => $variantBody['data']['productVariantsBulkCreate']['userErrors'] ?? $variantBody['errors'],
                    ];
                }

                $createdVariants = $variantBody['data']['productVariantsBulkCreate']['productVariants'] ?? [];
                Log::info('Variants created successfully', [
                    'count' => count($createdVariants),
                    'variants' => $createdVariants
                ]);
            }

            // Step 3: Wait and fetch all variants
            sleep(2);
            
            $fetchVariantsQuery = $this->buildFetchVariantsQueryWithDetails($shopifyProductId);
            $fetchResponse = $client->post('', ['json' => ['query' => $fetchVariantsQuery]]);
            $fetchBody = json_decode((string) $fetchResponse->getBody(), true);

            $allVariants = $fetchBody['data']['product']['variants']['edges'] ?? [];
            Log::info('Step 3: All variants fetched', ['count' => count($allVariants)]);

            // Step 4: Attach images to variants (inventory already set during creation)
            $variantImageMap = $this->buildVariantImageMap($data['variations'] ?? []);
            
            foreach ($allVariants as $edge) {
                $variant = $edge['node'];
                $variantId = $variant['id'];
                $variantTitle = $variant['title'];

                if (isset($variantImageMap[$variantTitle])) {
                    $imageUrls = $variantImageMap[$variantTitle];
                    $this->attachImagesToVariant($client, $shopifyProductId, $variantId, $imageUrls);
                }
            }

            // Step 5: Fetch final product state
            sleep(1);
            $finalFetchResponse = $client->post('', ['json' => ['query' => $fetchVariantsQuery]]);
            $finalFetchBody = json_decode((string) $finalFetchResponse->getBody(), true);

            // Step 6: Save product and variants locally
            $localProduct = null;
            $localVariations = [];

            try {
                if (class_exists(Product::class)) {
                    $localProduct = Product::create([
                        'shopify_product_id' => $shopifyProductId,
                        'title' => $shopifyProduct['title'] ?? $uniqueTitle,
                        'description' => $data['description'] ?? null,
                    ]);

                    // Save variations locally
                    if (class_exists(Variation::class) && $localProduct) {
                        $finalVariants = $finalFetchBody['data']['product']['variants']['edges'] ?? [];
                        
                        foreach ($finalVariants as $edge) {
                            $variant = $edge['node'];
                            $variantTitle = $variant['title'] ?? '';
                            
                            $localVariation = Variation::create([
                                'product_id' => $localProduct->id,
                                'shopify_variant_id' => $variant['id'],
                                'title' => $variantTitle,
                                'price' => $variant['price'] ?? '0.00',
                                'inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                            ]);
                            
                            $localVariations[] = $localVariation;

                            // Save images for this variation
                            if (class_exists(\App\Models\Image::class)) {
                                $imageUrls = $variantImageMap[$variantTitle] ?? [];
                                
                                foreach ($imageUrls as $imageUrl) {
                                    \App\Models\Image::create([
                                        'variation_id' => $localVariation->id,
                                        'src' => $imageUrl,
                                    ]);
                                }
                            }
                        }
                        
                        Log::info('Local variations saved', ['count' => count($localVariations)]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Local product/variation save failed', ['error' => $e->getMessage()]);
            }

            return [
                'success' => true,
                'message' => 'Product and variants created successfully',
                'product' => $localProduct,
                'variations' => $localVariations,
                'shopify_result' => $finalFetchBody['data']['product'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('GuzzleException during Shopify product creation', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Network/HTTP error: ' . $e->getMessage(),
                'data' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Exception during Shopify product creation', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Build variant inputs for productVariantsBulkCreate with inventory.
     *
     * @param array $variations
     * @param array $productOptions
     * @param string $locationId
     * @return array
     */
    protected function buildVariantInputs(array $variations, array $productOptions, string $locationId): array
    {
        $variantInputs = [];

        // Create option ID map
        $optionMap = [];
        foreach ($productOptions as $option) {
            $optionMap[$option['name']] = $option['id'];
        }

        foreach ($variations as $variation) {
            $title = $variation['title'] ?? '';
            $parts = array_map('trim', explode('/', $title));

            // Build optionValues array
            $optionValues = [];
            foreach ($parts as $index => $value) {
                $optionName = $this->getOptionName($index);
                
                if (isset($optionMap[$optionName]) && $value !== '') {
                    $optionValues[] = [
                        'optionId' => $optionMap[$optionName],
                        'name' => $value,
                    ];
                }
            }

            if (!empty($optionValues)) {
                $variantInput = [
                    'optionValues' => $optionValues,
                    'price' => $variation['price'] ?? '0.00',
                ];
                
                // Add inventory quantities if provided
                if (isset($variation['inventory_quantity']) && $locationId) {
                    $variantInput['inventoryQuantities'] = [
                        [
                            'availableQuantity' => (int)$variation['inventory_quantity'],
                            'locationId' => $locationId,
                        ]
                    ];
                }
                
                $variantInputs[] = $variantInput;
            }
        }

        return $variantInputs;
    }

    /**
     * Build a map of variant titles to their image URLs.
     *
     * @param array $variations
     * @return array Array of [title => [url1, url2, ...]]
     */
    protected function buildVariantImageMap(array $variations): array
    {
        $map = [];
        
        foreach ($variations as $variation) {
            $title = trim($variation['title'] ?? '');
            $images = $variation['images'] ?? [];
            
            if (empty($images) || $title === '') {
                continue;
            }
            
            // Extract URLs from images array
            $imageUrls = [];
            foreach ($images as $image) {
                if (is_string($image)) {
                    $imageUrls[] = $image;
                } elseif (is_array($image) && isset($image['src'])) {
                    $imageUrls[] = $image['src'];
                }
            }
            
            if (!empty($imageUrls)) {
                $map[$title] = $imageUrls;
            }
        }
        
        return $map;
    }

    /**
     * Attach images to a variant.
     * Uploads all images first, then attaches them one by one.
     *
     * @param Client $client
     * @param string $productId
     * @param string $variantId
     * @param array $imageUrls Array of image URLs
     * @return void
     */
    protected function attachImagesToVariant(Client $client, string $productId, string $variantId, array $imageUrls): void
    {
        if (empty($imageUrls)) {
            return;
        }

        // Step 1: Upload all images and collect their media IDs
        $mediaIds = [];
        
        foreach ($imageUrls as $imageUrl) {
            if (empty($imageUrl)) {
                continue;
            }
            
            $mediaId = $this->createProductMediaAndGetId($client, $productId, $imageUrl);
            
            if ($mediaId) {
                $mediaIds[] = $mediaId;
            }
        }

        // Step 2: Attach each media ID separately (Shopify requirement)
        if (!empty($mediaIds)) {
            $this->attachMediaIdsToVariant($client, $productId, $variantId, $mediaIds);
        }
    }

    /**
     * Create product media and return the media ID once it's ready.
     *
     * @param Client $client
     * @param string $productId
     * @param string $imageUrl
     * @return string|null Media ID or null if failed
     */
    protected function createProductMediaAndGetId(Client $client, string $productId, string $imageUrl): ?string
    {
        try {
            // Validate URL format
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                Log::warning('Invalid image URL format', ['url' => $imageUrl]);
                return null;
            }

            // Step 1: Create media on the product
            $createMediaMutation = $this->buildProductCreateMediaMutation($productId, $imageUrl);

            $response = $client->post('', ['json' => ['query' => $createMediaMutation]]);
            $body = json_decode((string) $response->getBody(), true);
            
            $media = $body['data']['productCreateMedia']['media'] ?? [];
            $mediaUserErrors = $body['data']['productCreateMedia']['mediaUserErrors'] ?? [];
            $userErrors = $body['data']['productCreateMedia']['userErrors'] ?? [];
            
            if (!empty($mediaUserErrors) || !empty($userErrors)) {
                Log::error('Failed to create product media', [
                    'url' => $imageUrl,
                    'mediaUserErrors' => $mediaUserErrors,
                    'userErrors' => $userErrors
                ]);
                return null;
            }
            
            if (empty($media)) {
                Log::warning('No media created for URL', ['url' => $imageUrl]);
                return null;
            }

            $mediaId = $media[0]['id'] ?? null;
            $mediaStatus = $media[0]['status'] ?? 'UNKNOWN';
            
            if (!$mediaId) {
                Log::warning('No media ID returned');
                return null;
            }

            Log::info('Media created', [
                'mediaId' => $mediaId,
                'status' => $mediaStatus,
                'url' => $imageUrl
            ]);

            // Step 2: Wait for media to be ready
            $isReady = $this->waitForMediaReady($client, $productId, $mediaId);
            
            if (!$isReady) {
                Log::warning('Media never became ready', ['mediaId' => $mediaId]);
                return null;
            }

            return $mediaId;
            
        } catch (\Throwable $e) {
            Log::error('Exception creating product media', [
                'imageUrl' => $imageUrl,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Wait for media to become ready.
     *
     * @param Client $client
     * @param string $productId
     * @param string $mediaId
     * @return bool True if media is ready, false otherwise
     */
    protected function waitForMediaReady(Client $client, string $productId, string $mediaId): bool
    {
        $maxAttempts = 10;
        $delaySeconds = 2;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statusQuery = <<<GQL
{
  product(id: "{$productId}") {
    media(first: 100) {
      nodes {
        ... on MediaImage {
          id
          status
        }
      }
    }
  }
}
GQL;

            try {
                $statusResponse = $client->post('', ['json' => ['query' => $statusQuery]]);
                $statusBody = json_decode((string) $statusResponse->getBody(), true);
                
                $allMedia = $statusBody['data']['product']['media']['nodes'] ?? [];
                $targetMedia = null;
                
                foreach ($allMedia as $media) {
                    if ($media['id'] === $mediaId) {
                        $targetMedia = $media;
                        break;
                    }
                }
                
                $mediaStatus = $targetMedia['status'] ?? 'UNKNOWN';
                
                Log::info('Media status check', [
                    'attempt' => $attempt,
                    'mediaId' => $mediaId,
                    'status' => $mediaStatus
                ]);
                
                if ($mediaStatus === 'READY') {
                    return true;
                }
                
                if ($mediaStatus === 'FAILED') {
                    Log::error('Media upload failed', ['mediaId' => $mediaId]);
                    return false;
                }
                
                // Wait before next attempt
                if ($attempt < $maxAttempts) {
                    sleep($delaySeconds);
                }
                
            } catch (\Throwable $e) {
                Log::error('Exception checking media status', [
                    'mediaId' => $mediaId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return false;
    }

   /**
 * Attach multiple media IDs to a variant.
 *
 * Shopify GraphQL is strict: you cannot list the same variant more than once
 * inside the same productVariantAppendMedia mutation payload. If you must
 * attach multiple media items to the same variant, perform separate
 * productVariantAppendMedia mutations — one mediaId per mutation.
 *
 * This implementation:
 *  - Sends one mutation per mediaId (single-object variantMedia array).
 *  - Retries a few times on transient errors.
 *  - Logs and skips userErrors that indicate duplicates/already-attached.
 *
 * @param Client $client
 * @param string $productId
 * @param string $variantId
 * @param array $mediaIds Array of media ID strings
 * @return void
 */
protected function attachMediaIdsToVariant(Client $client, string $productId, string $variantId, array $mediaIds): void
{
    if (empty($mediaIds)) {
        return;
    }

    $maxRetries = 2;
    $delayBetweenRequests = 1; // seconds - small pause to avoid throttling

    foreach ($mediaIds as $mediaId) {
        if (empty($mediaId)) {
            continue;
        }

        $escapedProductId = $this->escapeGraphQL($productId);
        $escapedVariantId = $this->escapeGraphQL($variantId);
        $escapedMediaId   = $this->escapeGraphQL($mediaId);

        // Build a single-entry variantMedia array (one object only)
        $variantMediaBlock = sprintf('[{ variantId: "%s", mediaIds: ["%s"] }]', $escapedVariantId, $escapedMediaId);

        $attachMutation = <<<GQL
mutation {
  productVariantAppendMedia(
    productId: "{$escapedProductId}",
    variantMedia: {$variantMediaBlock}
  ) {
    productVariants {
      id
      media(first: 20) {
        nodes {
          ... on MediaImage {
            id
            image {
              url
            }
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

        $attempt = 0;
        $attached = false;

        while ($attempt <= $maxRetries && !$attached) {
            $attempt++;

            try {
                $resp = $client->post('', ['json' => ['query' => $attachMutation]]);
                $body = json_decode((string) $resp->getBody(), true);

                // Top-level GraphQL errors (network/parse)
                if (!empty($body['errors'])) {
                    Log::warning('GraphQL errors returned when attaching media to variant', [
                        'variantId' => $variantId,
                        'mediaId' => $mediaId,
                        'attempt' => $attempt,
                        'errors' => $body['errors']
                    ]);
                    // retry on top-level errors
                    if ($attempt <= $maxRetries) {
                        sleep(1);
                        continue;
                    } else {
                        break;
                    }
                }

                $result = $body['data']['productVariantAppendMedia'] ?? null;
                if ($result === null) {
                    Log::warning('Empty productVariantAppendMedia result', [
                        'variantId' => $variantId,
                        'mediaId' => $mediaId,
                        'body' => $body
                    ]);
                    if ($attempt <= $maxRetries) {
                        sleep(1);
                        continue;
                    } else {
                        break;
                    }
                }

                $userErrors = $result['userErrors'] ?? [];

                if (!empty($userErrors)) {
                    // Inspect common userErrors and decide action
                    $messages = array_map(function($e) {
                        return is_array($e) ? ($e['message'] ?? json_encode($e)) : (string)$e;
                    }, $userErrors);

                    Log::warning('User errors returned when attaching media to variant', [
                        'variantId' => $variantId,
                        'mediaId' => $mediaId,
                        'attempt' => $attempt,
                        'userErrors' => $userErrors
                    ]);

                    // If error message indicates duplicate or already-attached, skip attaching this media
                    $skip = false;
                    foreach ($messages as $msg) {
                        $lower = strtolower($msg);
                        if (str_contains($lower, 'variant was specified in more than one media input')
                            || str_contains($lower, 'only one mediaid is allowed')
                            || str_contains($lower, 'already attached')
                            || str_contains($lower, 'duplicate')) {
                            $skip = true;
                            break;
                        }
                    }

                    if ($skip) {
                        Log::info('Skipping media attachment due to userErrors', [
                            'variantId' => $variantId,
                            'mediaId' => $mediaId,
                            'userErrors' => $userErrors
                        ]);
                        // don't retry this mediaId any further
                        break;
                    }

                    // For other userErrors, retry up to maxRetries
                    if ($attempt <= $maxRetries) {
                        sleep(1);
                        continue;
                    } else {
                        break;
                    }
                }

                // If we reach here, assume success and record/log the result
                $productVariants = $result['productVariants'] ?? [];
                Log::info('Image attached to variant', [
                    'variantId' => $variantId,
                    'mediaId' => $mediaId,
                    'attempt' => $attempt,
                    'productVariants' => $productVariants
                ]);

                $attached = true;
            } catch (\Throwable $e) {
                Log::error('Exception attaching media to variant', [
                    'variantId' => $variantId,
                    'mediaId' => $mediaId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt <= $maxRetries) {
                    sleep(1);
                    continue;
                } else {
                    // give up on this media and continue to next mediaId
                    break;
                }
            }
        } // end while attempts

        // Small delay between separate mutations to be polite with Shopify
        sleep($delayBetweenRequests);
    } // end foreach mediaId
}


    /**
     * Build productCreateMedia mutation for URL.
     *
     * @param string $productId
     * @param string $imageUrl
     * @return string
     */
    protected function buildProductCreateMediaMutation(string $productId, string $imageUrl): string
    {
        $escapedUrl = $this->escapeGraphQL($imageUrl);
        
        return <<<GQL
mutation {
  productCreateMedia(
    productId: "{$productId}",
    media: [
      {
        alt: "Product image",
        mediaContentType: IMAGE,
        originalSource: "{$escapedUrl}"
      }
    ]
  ) {
    media {
      ... on MediaImage {
        id
        status
        image {
          url
        }
      }
    }
    mediaUserErrors {
      field
      message
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    /**
     * Build productVariantsBulkCreate mutation with inventory.
     *
     * @param string $productId
     * @param array $variantInputs
     * @return string
     */
    protected function buildProductVariantsBulkCreateMutation(string $productId, array $variantInputs): string
    {
        $variantsParts = [];

        foreach ($variantInputs as $input) {
            $price = $this->escapeGraphQL($input['price']);

            // Build optionValues
            $optionValuesParts = [];
            foreach ($input['optionValues'] as $optionValue) {
                $optionValuesParts[] = sprintf(
                    '{ optionId: "%s", name: "%s" }',
                    $this->escapeGraphQL($optionValue['optionId']),
                    $this->escapeGraphQL($optionValue['name'])
                );
            }
            $optionValuesBlock = '[' . implode(', ', $optionValuesParts) . ']';

            // Build inventoryQuantities if provided
            $inventoryBlock = '';
            if (isset($input['inventoryQuantities']) && !empty($input['inventoryQuantities'])) {
                $invParts = [];
                foreach ($input['inventoryQuantities'] as $invQty) {
                    $invParts[] = sprintf(
                        '{ availableQuantity: %d, locationId: "%s" }',
                        $invQty['availableQuantity'],
                        $this->escapeGraphQL($invQty['locationId'])
                    );
                }
                $inventoryBlock = ', inventoryQuantities: [' . implode(', ', $invParts) . ']';
            }

            // Build variant input
            $variantsParts[] = sprintf(
                '{ price: "%s", optionValues: %s%s }',
                $price,
                $optionValuesBlock,
                $inventoryBlock
            );
        }

        $variantsBlock = '[' . implode(', ', $variantsParts) . ']';
        $escapedProductId = $this->escapeGraphQL($productId);

        return <<<GQL
mutation {
  productVariantsBulkCreate(
    productId: "{$escapedProductId}",
    variants: {$variantsBlock},
    strategy: REMOVE_STANDALONE_VARIANT
  ) {
    productVariants {
      id
      title
      price
      inventoryQuantity
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    /**
     * Build the productCreate GraphQL mutation WITH options included.
     *
     * @param array $data
     * @param array $options
     * @return string
     */
    protected function buildProductCreateWithOptionsMutation(array $data, array $options): string
    {
        $title = $this->escapeGraphQL($data['title'] ?? '');
        $description = $this->escapeGraphQL($data['description'] ?? '');

        // Build options array for GraphQL (API 2025-07 format)
        $optionsParts = [];
        foreach ($options as $name => $values) {
            $valuesArray = '[' . implode(', ', array_map(function($v) {
                return '{name: "' . $this->escapeGraphQL($v) . '"}';
            }, $values)) . ']';
            
            $optionsParts[] = sprintf(
                '{ name: "%s", values: %s }',
                $this->escapeGraphQL($name),
                $valuesArray
            );
        }

        $optionsBlock = empty($optionsParts) ? '' : ', productOptions: [' . implode(', ', $optionsParts) . ']';

        return <<<GQL
mutation {
  productCreate(product: {
    title: "{$title}",
    descriptionHtml: "{$description}"{$optionsBlock}
  }) {
    product {
      id
      title
      options {
        id
        name
        position
        optionValues {
          id
          name
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

    /**
     * Build query to fetch variants with full details including inventory.
     *
     * @param string $productId
     * @return string
     */
    protected function buildFetchVariantsQueryWithDetails(string $productId): string
    {
        return <<<GQL
{
  product(id: "{$productId}") {
    id
    title
    variants(first: 250) {
      edges {
        node {
          id
          title
          price
          sku
          inventoryQuantity
        }
      }
    }
  }
}
GQL;
    }

    /**
     * Extract options map from variations.
     *
     * Returns: ['Color' => ['Red','Blue'], 'Size' => ['Small','Medium','Large']]
     *
     * @param array $variations
     * @return array
     */
    protected function extractProductOptions(array $variations): array
    {
        $map = [];

        foreach ($variations as $variant) {
            $parts = array_map('trim', explode('/', $variant['title'] ?? ''));

            foreach ($parts as $i => $value) {
                $name = $this->getOptionName($i);

                if (!isset($map[$name])) {
                    $map[$name] = [];
                }

                if ($value === '') {
                    continue;
                }

                if (!in_array($value, $map[$name], true)) {
                    $map[$name][] = $value;
                }
            }
        }

        return $map;
    }

    /**
     * Option name by index (0 => Color, 1 => Size, 2 => Material).
     *
     * @param int $index
     * @return string
     */
    protected function getOptionName(int $index): string
    {
        $names = ['Color', 'Size', 'Material'];
        return $names[$index] ?? 'Option' . ($index + 1);
    }

    /**
     * Escape string content for GraphQL literal usage.
     *
     * @param string $value
     * @return string
     */
    protected function escapeGraphQL(string $value): string
    {
        $value = str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\"'], $value);
        return $value;
    }
}