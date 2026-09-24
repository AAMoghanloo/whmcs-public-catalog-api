# WHMCS Public Catalog API

A lightweight and secure PHP endpoint for exposing your **WHMCS product catalog** as a JSON API.

This API is designed primarily for custom storefronts, external websites, frontend applications, and server-to-server integrations that need access to WHMCS product groups, products, pricing, availability, and stock information.

## Features

- Product groups and products from WHMCS
- Multi-currency pricing
- Recurring, one-time, and free products
- Product stock and availability information
- Product and group filtering
- Optional hidden product/group support
- API token authentication
- IP allowlisting with IPv4/IPv6 CIDR support
- HTTPS enforcement
- Optional CORS configuration
- Built-in rate limiting
- ETag support
- Configurable response caching
- `GET` and `HEAD` request support
- Secure JSON error responses
- WHMCS Local API integration

## Requirements

- PHP 8.1+
- WHMCS installation
- WHMCS 9.0.x requires PHP 8.2+
- HTTPS is strongly recommended and enabled by default

## Installation

Place the PHP API file in the **root directory of your WHMCS installation**, next to:

```text
init.php
```

Example:

```text
/path/to/whmcs/
├── init.php
├── configuration.php
├── vendor/
├── ...
└── catalog-api.php
```

The endpoint automatically loads WHMCS using:

```php
require_once __DIR__ . '/init.php';
```

## Configuration

Edit the `$config` array near the top of the script.

### API Token

The recommended method is to define the token as an environment variable:

```bash
WHMCS_CATALOG_API_TOKEN="your-long-random-secret"
```

The script reads it using:

```php
getenv('WHMCS_CATALOG_API_TOKEN')
```

You can also configure the token directly in the PHP file, although using an environment variable is preferred.

Use a strong random secret of at least 32 characters. A 64+ character random value is recommended.

Example:

```php
'api_token' => getenv('WHMCS_CATALOG_API_TOKEN') ?: 'YOUR_RANDOM_SECRET',
```

> Never commit a real production API token to GitHub.

## Authentication

Every request must include the API token.

You can use either the `X-Catalog-Key` header:

```http
X-Catalog-Key: YOUR_SECRET
```

or Bearer authentication:

```http
Authorization: Bearer YOUR_SECRET
```

Example with cURL:

```bash
curl \
  -H "X-Catalog-Key: YOUR_SECRET" \
  "https://billing.example.com/catalog-api.php"
```

Bearer authentication:

```bash
curl \
  -H "Authorization: Bearer YOUR_SECRET" \
  "https://billing.example.com/catalog-api.php"
```

## API Endpoint

```http
GET /catalog-api.php
```

The API also supports:

```http
HEAD /catalog-api.php
```

CORS preflight requests can use:

```http
OPTIONS /catalog-api.php
```

Other HTTP methods are rejected.

## Query Parameters

### `currency`

Return pricing in a specific WHMCS currency.

```text
?currency=USD
```

The value must be a valid 3-letter currency code configured in WHMCS.

Examples:

```text
?currency=USD
?currency=EUR
?currency=GBP
```

If omitted, the configured API default currency is used. If no API default is configured, the WHMCS default currency is used.

---

### `gid`

Return products from a specific WHMCS product group.

```text
?gid=3
```

---

### `pid`

Filter the response by WHMCS product ID.

```text
?pid=12
```

---

### `available_only`

Return only currently available products.

```text
?available_only=1
```

Accepted boolean values include values such as:

```text
1
true
yes
on
```

Example:

```text
https://billing.example.com/catalog-api.php?currency=USD&available_only=1
```

## Combined Filters

Parameters can be combined:

```bash
curl \
  -H "X-Catalog-Key: YOUR_SECRET" \
  "https://billing.example.com/catalog-api.php?currency=USD&gid=3&available_only=1"
```

## Example Response

A successful response has a structure similar to:

```json
{
  "success": true,
  "api_version": "1.1.0",
  "generated_at": "2026-01-01T12:00:00+00:00",
  "currency": {
    "id": 1,
    "code": "USD",
    "prefix": "$",
    "suffix": ""
  },
  "filters": {
    "gid": null,
    "pid": null,
    "available_only": false
  },
  "summary": {
    "category_count": 1,
    "plan_count": 2,
    "available_plan_count": 2,
    "out_of_stock_plan_count": 0
  },
  "categories": [
    {
      "id": 1,
      "name": "Hosting",
      "slug": "hosting",
      "whmcs_hidden": false,
      "available": true,
      "status": "available",
      "plan_count": 2,
      "available_plan_count": 2,
      "out_of_stock_plan_count": 0,
      "starting_price": {
        "cycle": "monthly",
        "amount": "4.99",
        "setup_fee": "0.00",
        "currency": "USD",
        "formatted": "$4.99"
      },
      "starting_available_price": {
        "cycle": "monthly",
        "amount": "4.99",
        "setup_fee": "0.00",
        "currency": "USD",
        "formatted": "$4.99"
      },
      "plans": [
        {
          "id": 1,
          "group_id": 1,
          "name": "Starter",
          "slug": "starter",
          "type": "hostingaccount",
          "pay_type": "recurring",
          "available": true,
          "whmcs_hidden": false,
          "stock": {
            "control_enabled": false,
            "quantity": null,
            "status": "unlimited"
          },
          "starting_price": {
            "cycle": "monthly",
            "amount": "4.99",
            "setup_fee": "0.00",
            "currency": "USD",
            "formatted": "$4.99"
          },
          "pricing": {
            "monthly": {
              "cycle": "monthly",
              "amount": "4.99",
              "setup_fee": "0.00",
              "currency": "USD",
              "formatted": "$4.99"
            }
          },
          "order_url": "https://billing.example.com/cart.php?a=add&pid=1"
        }
      ]
    }
  ]
}
```

> The values above are examples. Actual response values are generated from your WHMCS installation.

## Pricing

The API supports the standard WHMCS billing cycles:

```text
monthly
quarterly
semiannually
annually
biennially
triennially
```

It also supports:

```text
onetime
free
```

Each enabled price contains:

```json
{
  "cycle": "monthly",
  "amount": "9.99",
  "setup_fee": "0.00",
  "currency": "USD",
  "formatted": "$9.99"
}
```

Disabled or unavailable pricing cycles are omitted.

## Stock Status

Each product includes stock information.

### Unlimited

When WHMCS stock control is disabled:

```json
{
  "control_enabled": false,
  "quantity": null,
  "status": "unlimited"
}
```

### In Stock

```json
{
  "control_enabled": true,
  "quantity": 10,
  "status": "in_stock"
}
```

### Out of Stock

```json
{
  "control_enabled": true,
  "quantity": 0,
  "status": "out_of_stock"
}
```

Use:

```text
?available_only=1
```

to automatically exclude out-of-stock products.

## Hidden Products and Groups

By default, hidden WHMCS product groups and products are included.

This is useful when WHMCS is only being used as the billing backend while a separate website acts as the public storefront.

Configuration:

```php
'include_hidden_groups' => true,
'include_hidden_products' => true,
```

To expose only products visible in the native WHMCS order form:

```php
'include_hidden_groups' => false,
'include_hidden_products' => false,
```

Retired products are always excluded.

## IP Allowlist

You can restrict API access to specific IP addresses or CIDR ranges.

Example:

```php
'allowed_ips' => [
    '203.0.113.10',
    '2001:db8:1234::/48',
],
```

Both IPv4 and IPv6 are supported.

An empty array means there is no IP restriction:

```php
'allowed_ips' => [],
```

For production server-to-server integrations, configuring an IP allowlist is strongly recommended.

## HTTPS

HTTPS is required by default:

```php
'require_https' => true,
```

Requests made over plain HTTP will be rejected.

### Reverse Proxies and Cloudflare

If WHMCS is behind a reverse proxy or Cloudflare, configure the trusted proxy IP addresses or CIDR ranges:

```php
'trusted_proxy_ips' => [
    'YOUR_PROXY_IP_OR_CIDR'
],
```

The API only trusts `X-Forwarded-Proto` when the connection comes from a configured trusted proxy.

Do not add untrusted networks to this list.

## CORS

Browser CORS access is disabled by default:

```php
'allowed_origins' => [],
```

Server-to-server requests do not require CORS.

If you intentionally want to access the endpoint from browser JavaScript, add exact allowed origins:

```php
'allowed_origins' => [
    'https://www.example.com'
],
```

Do not use wildcard origins when using a secret API token.

> Calling this endpoint directly from frontend/browser JavaScript exposes the API token to visitors. Server-to-server usage is recommended.

## Rate Limiting

The API includes a simple built-in rate limiter.

Default configuration:

```php
'rate_limit_requests' => 120,
'rate_limit_window_seconds' => 60,
```

This allows:

```text
120 requests per 60 seconds
```

Rate-limit identity is based on the client IP and a hash of the supplied API token.

When the limit is exceeded, the API returns HTTP:

```text
429 Too Many Requests
```

along with a `Retry-After` header.

## Caching

Response caching can be configured using:

```php
'cache_seconds' => 30,
```

The API sends:

```http
Cache-Control: private, max-age=30, must-revalidate
```

It also generates an `ETag`.

Clients can send:

```http
If-None-Match: "..."
```

and receive:

```http
304 Not Modified
```

when the catalog has not changed.

## Security Headers

The endpoint sends several security-related HTTP headers, including:

```text
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy
Referrer-Policy: no-referrer
Permissions-Policy
```

PHP errors and stack traces are not exposed in API responses.

Unexpected errors are logged server-side instead.

## Error Response

Errors use a consistent JSON structure:

```json
{
  "success": false,
  "error": {
    "code": "unauthorized",
    "message": "Invalid API credentials."
  }
}
```

Some errors may also contain additional details:

```json
{
  "success": false,
  "error": {
    "code": "unsupported_currency",
    "message": "Requested currency is not configured in WHMCS.",
    "details": {
      "available_currencies": [
        "USD",
        "EUR"
      ]
    }
  }
}
```

## Common HTTP Status Codes

| Status | Meaning |
|---|---|
| `200` | Successful request |
| `204` | Successful CORS preflight |
| `304` | Response has not changed |
| `400` | Invalid request or HTTPS required |
| `401` | Invalid or missing API credentials |
| `403` | IP or origin is not allowed |
| `405` | Unsupported HTTP method |
| `429` | Rate limit exceeded |
| `500` | Internal API/WHMCS error |
| `503` | API token is not configured securely |

## Example: JavaScript / Node.js

For server-side JavaScript:

```javascript
const response = await fetch(
  'https://billing.example.com/catalog-api.php?currency=USD',
  {
    headers: {
      'X-Catalog-Key': process.env.WHMCS_CATALOG_API_TOKEN
    }
  }
);

if (!response.ok) {
  throw new Error(`Catalog API returned ${response.status}`);
}

const catalog = await response.json();

console.log(catalog.categories);
```

Keep the token on the server. Do not expose it in public frontend code.

## Example: PHP Client

```php
<?php

$url = 'https://billing.example.com/catalog-api.php?currency=USD';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-Catalog-Key: ' . getenv('WHMCS_CATALOG_API_TOKEN'),
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    throw new RuntimeException(curl_error($ch));
}

$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

curl_close($ch);

if ($status !== 200) {
    throw new RuntimeException("Catalog API returned HTTP {$status}");
}

$data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

print_r($data['categories']);
```

## Recommended Production Configuration

A typical secure server-to-server configuration might look like:

```php
$config = [
    'api_token' => getenv('WHMCS_CATALOG_API_TOKEN'),

    'allowed_ips' => [
        '203.0.113.10',
    ],

    'trusted_proxy_ips' => [],

    'allowed_origins' => [],

    'require_https' => true,

    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,

    'cache_seconds' => 30,

    'default_currency' => 'USD',

    'include_hidden_groups' => true,
    'include_hidden_products' => true,
];
```

## Security Recommendations

Before using this API in production:

1. Use a long cryptographically random API token.
2. Store the token in an environment variable.
3. Never commit production secrets to Git.
4. Keep HTTPS enabled.
5. Restrict access using `allowed_ips` whenever possible.
6. Keep CORS disabled unless browser access is absolutely necessary.
7. Never embed the API token in public JavaScript.
8. Configure trusted proxy IPs carefully.
9. Keep WHMCS and PHP updated.
10. Review your web server and PHP error logs regularly.

## API Version

Current API version:

```text
1.1.0
```

The version is returned in every successful response:

```json
{
  "api_version": "1.1.0"
}
```

## Use Cases

This endpoint can be used for:

- Custom WHMCS storefronts
- Hosting websites
- Product comparison pages
- Server-side rendered storefronts
- Mobile application backends
- Internal catalog integrations
- External dashboards
- Headless WHMCS implementations

## Important

This project is intended primarily for **server-to-server communication**.

If you expose the API token inside frontend JavaScript, browser extensions, visitors, or other clients may be able to retrieve it.

For public websites, a recommended architecture is:

```text
Browser
   │
   ▼
Your Website / Backend
   │
   │  X-Catalog-Key
   ▼
WHMCS Catalog API
   │
   ▼
WHMCS
```

instead of:

```text
Browser ─── Secret API Token ───> WHMCS Catalog API
```

## License

No license is included by default.

If you intend to publish this project as open source, add a `LICENSE` file and specify your preferred license here.

For example:

```text
MIT License
```

## Disclaimer

This project is an independent integration for WHMCS.

WHMCS is a trademark of its respective owner. This project is not officially affiliated with or endorsed by WHMCS.
