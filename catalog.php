<?php
/**
 * WHMCS Public Catalog API
 * Compatible syntax: PHP 8.1+
 * WHMCS 9.0.x runtime requirement: PHP 8.2+
 *
 * Upload this file to the WHMCS installation root, next to init.php.
 *
 * Recommended usage: server-to-server only.
 * Send the API token in one of these headers:
 *   X-Catalog-Key: YOUR_SECRET
 *   Authorization: Bearer YOUR_SECRET
 *
 * Optional query parameters:
 *   ?currency=USD
 *   ?gid=3
 *   ?pid=12
 *   ?available_only=1
 */

declare(strict_types=1);

// Never expose PHP/WHMCS warnings or stack traces in a public API response.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

$config = [
    // Best option: define WHMCS_CATALOG_API_TOKEN as an environment variable.
    // Otherwise replace the placeholder below with a random 64+ character secret.
    'api_token' => getenv('your-long-random-secret') ?: 'your-long-random-secret',

    // Strongly recommended: put the main website/backend public IP(s) here.
    // Exact IP and CIDR are supported. Empty array = no IP restriction.
    // Examples: ['203.0.113.10', '2001:db8:1234::/48']
    'allowed_ips' => [],

    // If WHMCS is behind Cloudflare/reverse proxy, add ONLY trusted proxy IP/CIDR
    // ranges here. X-Forwarded-Proto is trusted only from these addresses.
    'trusted_proxy_ips' => [],

    // Browser CORS is disabled by default. Server-to-server requests do not need it.
    // If you intentionally call this API from browser JS, add exact origins here.
    // WARNING: browser-side calls expose the API token to visitors.
    // Example: ['https://www.example.com']
    'allowed_origins' => [],

    'require_https' => true,
    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,
    'cache_seconds' => 30,

    // null = WHMCS default currency. Example: 'USD', 'EUR', 'IRR'.
    'default_currency' => null,

    // A custom storefront often keeps WHMCS groups/products hidden from the native
    // WHMCS order form. Hidden does NOT mean retired/inactive, so include hidden
    // catalog items by default. Set either option to false if you only want items
    // visible in the native WHMCS cart. Retired products are always excluded.
    'include_hidden_groups' => true,
    'include_hidden_products' => true,
];

const CATALOG_API_VERSION = '1.1.0';

// -----------------------------------------------------------------------------
// Generic helpers - kept before WHMCS bootstrap so rejected requests stay cheap.
// -----------------------------------------------------------------------------

function headerValue(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        return trim($_SERVER[$key]);
    }

    if (strcasecmp($name, 'Authorization') === 0) {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $authKey) {
            if (isset($_SERVER[$authKey]) && is_string($_SERVER[$authKey])) {
                return trim($_SERVER[$authKey]);
            }
        }
    }

    return '';
}

function jsonResponse(array $payload, int $status = 200, array $extraHeaders = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    foreach ($extraHeaders as $header) {
        header($header);
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION
    );

    if ($json === false) {
        $json = '{"success":false,"error":{"code":"json_encode_failed","message":"Unable to encode response."}}';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $json;
    }

    exit;
}

function errorResponse(string $code, string $message, int $status, array $extra = [], array $headers = []): never
{
    $payload = [
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];

    if ($extra !== []) {
        $payload['error']['details'] = $extra;
    }

    jsonResponse($payload, $status, $headers);
}

function ipMatchesRule(string $ip, string $rule): bool
{
    $ip = trim($ip);
    $rule = trim($rule);

    if ($ip === '' || $rule === '') {
        return false;
    }

    if (!str_contains($rule, '/')) {
        return $ip === $rule;
    }

    [$subnet, $bitsRaw] = array_pad(explode('/', $rule, 2), 2, '');
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    if (!ctype_digit($bitsRaw)) {
        return false;
    }

    $bits = (int) $bitsRaw;
    $maxBits = strlen($ipBin) * 8;

    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    $remainingBits = $bits % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
}

function ipAllowed(string $ip, array $rules): bool
{
    if ($rules === []) {
        return true;
    }

    foreach ($rules as $rule) {
        if (is_string($rule) && ipMatchesRule($ip, $rule)) {
            return true;
        }
    }

    return false;
}

function isHttpsRequest(array $trustedProxyIps): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!ipAllowed($remoteIp, $trustedProxyIps) || $trustedProxyIps === []) {
        return false;
    }

    $forwardedProto = strtolower(trim(explode(',', headerValue('X-Forwarded-Proto'))[0] ?? ''));
    return $forwardedProto === 'https';
}

function configuredToken(array $config): string
{
    return trim((string) ($config['api_token'] ?? ''));
}

function suppliedToken(): string
{
    $token = headerValue('X-Catalog-Key');
    if ($token !== '') {
        return $token;
    }

    $authorization = headerValue('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function enforceRateLimit(string $identity, int $limit, int $window): void
{
    if ($limit <= 0 || $window <= 0) {
        return;
    }

    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'whmcs_catalog_api_rl';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        // Do not break catalog availability if the temp directory is unavailable.
        error_log('[WHMCS Catalog API] Rate-limit directory could not be created.');
        return;
    }

    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        error_log('[WHMCS Catalog API] Rate-limit state could not be opened.');
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $now = time();

        if (!is_array($state) || !isset($state['start'], $state['count']) || ($now - (int) $state['start']) >= $window) {
            $state = ['start' => $now, 'count' => 0];
        }

        $state['count'] = (int) $state['count'] + 1;
        $retryAfter = max(1, $window - ($now - (int) $state['start']));

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);

        if ($state['count'] > $limit) {
            errorResponse(
                'rate_limit_exceeded',
                'Too many requests.',
                429,
                ['retry_after_seconds' => $retryAfter],
                ['Retry-After: ' . $retryAfter]
            );
        }
    } finally {
        fclose($handle);
    }
}

function positiveIntQuery(string $name): ?int
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return null;
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        errorResponse('invalid_parameter', "Invalid {$name} parameter.", 400);
    }

    return (int) $value;
}

function boolQuery(string $name, bool $default = false): bool
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $default;
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null) {
        errorResponse('invalid_parameter', "Invalid {$name} parameter.", 400);
    }

    return $value;
}

function boolValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function validEnabledPrice(mixed $value): bool
{
    if ($value === null || $value === '' || !is_numeric((string) $value)) {
        return false;
    }

    return (float) $value >= 0;
}

function displayPrice(string $amount, string $prefix, string $suffix): string
{
    return trim($prefix . $amount . $suffix);
}

function priceObject(string $amount, string $setupFee, string $cycle, string $currency, string $prefix, string $suffix): array
{
    return [
        'cycle' => $cycle,
        'amount' => $amount,
        'setup_fee' => $setupFee,
        'currency' => $currency,
        'formatted' => displayPrice($amount, $prefix, $suffix),
    ];
}

function buildPricing(array $apiProduct, string $currencyCode, array $currencyMeta): array
{
    $payType = strtolower((string) ($apiProduct['paytype'] ?? 'recurring'));
    $currencyPricing = $apiProduct['pricing'][$currencyCode] ?? [];

    if (!is_array($currencyPricing)) {
        $currencyPricing = [];
    }

    $prefix = (string) ($currencyPricing['prefix'] ?? $currencyMeta['prefix'] ?? '');
    $suffix = (string) ($currencyPricing['suffix'] ?? $currencyMeta['suffix'] ?? '');

    if ($payType === 'free') {
        $freePrice = priceObject('0.00', '0.00', 'free', $currencyCode, $prefix, $suffix);
        return [
            'pay_type' => 'free',
            'starting_price' => $freePrice,
            'cycles' => ['free' => $freePrice],
        ];
    }

    $cycleMap = [
        'monthly' => 'msetupfee',
        'quarterly' => 'qsetupfee',
        'semiannually' => 'ssetupfee',
        'annually' => 'asetupfee',
        'biennially' => 'bsetupfee',
        'triennially' => 'tsetupfee',
    ];

    $cycles = [];

    // WHMCS stores a one-time product charge in the monthly pricing slot.
    if ($payType === 'onetime') {
        $amount = $currencyPricing['monthly'] ?? null;
        if (validEnabledPrice($amount)) {
            $setup = $currencyPricing['msetupfee'] ?? '0.00';
            $oneTime = priceObject((string) $amount, (string) $setup, 'onetime', $currencyCode, $prefix, $suffix);
            $cycles['onetime'] = $oneTime;
        }
    } else {
        foreach ($cycleMap as $cycle => $setupField) {
            $amount = $currencyPricing[$cycle] ?? null;
            if (!validEnabledPrice($amount)) {
                continue;
            }

            $setup = $currencyPricing[$setupField] ?? '0.00';
            $cycles[$cycle] = priceObject((string) $amount, (string) $setup, $cycle, $currencyCode, $prefix, $suffix);
        }
    }

    $startingPrice = $cycles !== [] ? reset($cycles) : null;

    return [
        'pay_type' => $payType,
        'starting_price' => $startingPrice,
        'cycles' => $cycles,
    ];
}

function normalizeApiProducts(mixed $products): array
{
    if (!is_array($products) || $products === []) {
        return [];
    }

    if (isset($products['pid'])) {
        return [$products];
    }

    return array_values(array_filter($products, 'is_array'));
}

function fetchApiProductsByIds(array $productIds): array
{
    $map = [];

    foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), 100) as $chunk) {
        if ($chunk === []) {
            continue;
        }

        $result = localAPI('GetProducts', [
            'pid' => implode(',', $chunk),
        ]);

        if (!is_array($result) || ($result['result'] ?? '') !== 'success') {
            throw new RuntimeException('WHMCS GetProducts failed.');
        }

        $items = normalizeApiProducts($result['products']['product'] ?? []);
        foreach ($items as $item) {
            $pid = (int) ($item['pid'] ?? 0);
            if ($pid > 0) {
                $map[$pid] = $item;
            }
        }
    }

    return $map;
}

// -----------------------------------------------------------------------------
// Security gate
// -----------------------------------------------------------------------------

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    errorResponse('method_not_allowed', 'Only GET and HEAD are allowed.', 405, [], ['Allow: GET, HEAD, OPTIONS']);
}

$origin = headerValue('Origin');
if ($origin !== '') {
    $allowedOrigins = array_values(array_filter($config['allowed_origins'], 'is_string'));
    if (!in_array($origin, $allowedOrigins, true)) {
        errorResponse('origin_not_allowed', 'Origin is not allowed.', 403);
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, X-Catalog-Key, Accept');
    header('Access-Control-Max-Age: 600');
}

if ($method === 'OPTIONS') {
    if ($origin === '') {
        errorResponse('origin_required', 'CORS origin is required for preflight.', 400);
    }
    http_response_code(204);
    exit;
}

if (($config['require_https'] ?? true) && !isHttpsRequest((array) ($config['trusted_proxy_ips'] ?? []))) {
    errorResponse('https_required', 'HTTPS is required.', 400);
}

$remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!ipAllowed($remoteIp, (array) ($config['allowed_ips'] ?? []))) {
    errorResponse('ip_not_allowed', 'Access denied.', 403);
}

$expectedToken = configuredToken($config);
if ($expectedToken === '' || str_starts_with($expectedToken, 'CHANGE_ME_') || strlen($expectedToken) < 32) {
    errorResponse('api_not_configured', 'API token is not configured securely.', 503);
}

$providedToken = suppliedToken();
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    errorResponse('unauthorized', 'Invalid API credentials.', 401, [], ['WWW-Authenticate: Bearer realm="WHMCS Catalog API"']);
}

enforceRateLimit(
    $remoteIp . '|' . hash('sha256', $providedToken),
    (int) ($config['rate_limit_requests'] ?? 120),
    (int) ($config['rate_limit_window_seconds'] ?? 60)
);

// -----------------------------------------------------------------------------
// Validate public query parameters before loading WHMCS.
// -----------------------------------------------------------------------------

$gidFilter = positiveIntQuery('gid');
$pidFilter = positiveIntQuery('pid');
$availableOnly = boolQuery('available_only', false);

$requestedCurrency = null;
if (isset($_GET['currency']) && $_GET['currency'] !== '') {
    $requestedCurrency = strtoupper(trim((string) $_GET['currency']));
    if (preg_match('/^[A-Z]{3}$/', $requestedCurrency) !== 1) {
        errorResponse('invalid_currency', 'Currency must be a 3-letter code.', 400);
    }
}

// -----------------------------------------------------------------------------
// WHMCS bootstrap and catalog data
// -----------------------------------------------------------------------------

$requestId = bin2hex(random_bytes(8));

try {
    $initFile = __DIR__ . DIRECTORY_SEPARATOR . 'init.php';
    if (!is_file($initFile)) {
        throw new RuntimeException('WHMCS init.php not found.');
    }

    require_once $initFile;

    // This endpoint is stateless; release any session lock WHMCS may have opened.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Get configured currencies and detect the WHMCS default currency.
    $currencyModels = \WHMCS\Billing\Currency::defaultSorting()->get();
    $currencyMap = [];

    foreach ($currencyModels as $currencyModel) {
        $code = strtoupper((string) $currencyModel->code);
        if ($code === '') {
            continue;
        }

        $currencyMap[$code] = [
            'id' => (int) $currencyModel->id,
            'code' => $code,
            'prefix' => (string) $currencyModel->prefix,
            'suffix' => (string) $currencyModel->suffix,
        ];
    }

    $defaultCurrencyModel = \WHMCS\Billing\Currency::defaultCurrency()->first();
    $defaultCurrencyCode = $defaultCurrencyModel ? strtoupper((string) $defaultCurrencyModel->code) : '';

    $configuredDefault = $config['default_currency'] !== null
        ? strtoupper(trim((string) $config['default_currency']))
        : '';

    $currencyCode = $requestedCurrency
        ?? ($configuredDefault !== '' ? $configuredDefault : $defaultCurrencyCode);

    if ($currencyCode === '' && $currencyMap !== []) {
        $currencyCode = (string) array_key_first($currencyMap);
    }

    if ($currencyCode === '' || !isset($currencyMap[$currencyCode])) {
        errorResponse(
            'unsupported_currency',
            'Requested currency is not configured in WHMCS.',
            400,
            ['available_currencies' => array_keys($currencyMap)]
        );
    }

    // Hidden in WHMCS means "not listed in the native order form", not inactive.
    // For a separate storefront we therefore include hidden groups/products by
    // default, while retired products are always excluded from the API.
    $groupQuery = \WHMCS\Product\Group::sorted();
    if (!boolValue($config['include_hidden_groups'] ?? true)) {
        $groupQuery->notHidden();
    }
    if ($gidFilter !== null) {
        $groupQuery->where('id', $gidFilter);
    }

    $groups = $groupQuery->get();
    $catalogGroups = [];
    $allProductIds = [];

    foreach ($groups as $group) {
        $productQuery = $group->products()->isNotRetired()->sorted();
        if (!boolValue($config['include_hidden_products'] ?? true)) {
            $productQuery->visible();
        }
        if ($pidFilter !== null) {
            $productQuery->where('id', $pidFilter);
        }

        $products = $productQuery->get();
        if ($products->isEmpty()) {
            continue;
        }

        $catalogGroups[] = [
            'model' => $group,
            'products' => $products,
        ];

        foreach ($products as $product) {
            $allProductIds[] = (int) $product->id;
        }
    }

    $apiProducts = fetchApiProductsByIds($allProductIds);

    $categories = [];
    $totalPlans = 0;
    $availablePlans = 0;
    $outOfStockPlans = 0;

    foreach ($catalogGroups as $catalogGroup) {
        $group = $catalogGroup['model'];
        $plans = [];

        foreach ($catalogGroup['products'] as $product) {
            $pid = (int) $product->id;
            $apiProduct = $apiProducts[$pid] ?? null;

            if (!is_array($apiProduct)) {
                // Fail closed for a product whose public pricing could not be resolved.
                continue;
            }

            $stockControlled = boolValue($product->stockcontrol ?? false);
            $stockQuantity = $stockControlled ? max(0, (int) ($product->qty ?? 0)) : null;
            $isAvailable = !$stockControlled || ($stockQuantity !== null && $stockQuantity > 0);

            if ($availableOnly && !$isAvailable) {
                continue;
            }

            $pricing = buildPricing($apiProduct, $currencyCode, $currencyMap[$currencyCode]);

            $stockStatus = !$stockControlled
                ? 'unlimited'
                : ($isAvailable ? 'in_stock' : 'out_of_stock');

            $plan = [
                'id' => $pid,
                'group_id' => (int) $group->id,
                'name' => (string) $product->name,
                'slug' => (string) ($product->slug ?? ''),
                'type' => (string) ($product->type ?? $apiProduct['type'] ?? ''),
                'pay_type' => (string) ($apiProduct['paytype'] ?? $product->paytype ?? ''),
                'available' => $isAvailable,
                'whmcs_hidden' => boolValue($product->hidden ?? false),
                'stock' => [
                    'control_enabled' => $stockControlled,
                    'quantity' => $stockQuantity,
                    'status' => $stockStatus,
                ],
                'starting_price' => $pricing['starting_price'],
                'pricing' => $pricing['cycles'],
                'order_url' => (string) ($apiProduct['product-url'] ?? $apiProduct['product_url'] ?? ''),
            ];

            $plans[] = $plan;
            $totalPlans++;

            if ($isAvailable) {
                $availablePlans++;
            } else {
                $outOfStockPlans++;
            }
        }

        if ($plans === []) {
            continue;
        }

        $firstPlan = $plans[0];
        $firstAvailablePlan = null;
        foreach ($plans as $plan) {
            if ($plan['available']) {
                $firstAvailablePlan = $plan;
                break;
            }
        }

        $groupAvailableCount = count(array_filter($plans, static fn (array $plan): bool => $plan['available']));
        $groupOutOfStockCount = count($plans) - $groupAvailableCount;

        $categories[] = [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'slug' => (string) ($group->slug ?? ''),
            'whmcs_hidden' => boolValue($group->hidden ?? false),
            'available' => $groupAvailableCount > 0,
            'status' => $groupAvailableCount > 0 ? 'available' : 'out_of_stock',
            'plan_count' => count($plans),
            'available_plan_count' => $groupAvailableCount,
            'out_of_stock_plan_count' => $groupOutOfStockCount,

            // Exactly as requested: starting price is based on the first displayed plan.
            'starting_price' => $firstPlan['starting_price'],

            // Also useful when the first plan is out of stock.
            'starting_available_price' => $firstAvailablePlan['starting_price'] ?? null,
            'plans' => $plans,
        ];
    }

    $payload = [
        'success' => true,
        'api_version' => CATALOG_API_VERSION,
        'generated_at' => gmdate('c'),
        'currency' => $currencyMap[$currencyCode],
        'filters' => [
            'gid' => $gidFilter,
            'pid' => $pidFilter,
            'available_only' => $availableOnly,
        ],
        'summary' => [
            'category_count' => count($categories),
            'plan_count' => $totalPlans,
            'available_plan_count' => $availablePlans,
            'out_of_stock_plan_count' => $outOfStockPlans,
        ],
        'categories' => $categories,
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION
    );

    if ($json === false) {
        throw new RuntimeException('Could not encode catalog response.');
    }

    $etag = '"' . hash('sha256', $json) . '"';
    $cacheSeconds = max(0, (int) ($config['cache_seconds'] ?? 30));

    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=' . $cacheSeconds . ', must-revalidate');
    header('X-Request-ID: ' . $requestId);

    if (trim(headerValue('If-None-Match')) === $etag) {
        http_response_code(304);
        exit;
    }

    jsonResponse($payload, 200);
} catch (Throwable $e) {
    error_log(sprintf(
        '[WHMCS Catalog API] request_id=%s error=%s file=%s line=%d',
        $requestId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    errorResponse(
        'internal_error',
        'Unable to load the service catalog.',
        500,
        ['request_id' => $requestId]
    );
}
