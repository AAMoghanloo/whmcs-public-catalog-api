# WHMCS Public Catalog API

A lightweight and secure PHP API for exposing WHMCS products, pricing, stock, and categories as JSON.

## Features

- WHMCS product groups and products
- Multi-currency pricing
- Stock and availability status
- API token authentication
- IP allowlist support
- HTTPS enforcement
- Rate limiting
- Optional CORS
- ETag and response caching

## Requirements

- PHP 8.1+
- WHMCS
- PHP 8.2+ for WHMCS 9.x

## Installation

Place the API file in your WHMCS root directory, next to `init.php`.

Example:

```text
/whmcs/
├── init.php
├── configuration.php
└── catalog-api.php
```

## Configuration

Set your API token using an environment variable:

```bash
WHMCS_CATALOG_API_TOKEN="your-long-random-secret"
```

You can also configure:

```php
'allowed_ips' => [],
'trusted_proxy_ips' => [],
'allowed_origins' => [],
'require_https' => true,
'rate_limit_requests' => 120,
'rate_limit_window_seconds' => 60,
'cache_seconds' => 30,
'default_currency' => null,
```

## Authentication

Use one of these headers:

```http
X-Catalog-Key: YOUR_SECRET
```

or:

```http
Authorization: Bearer YOUR_SECRET
```

## Usage

```bash
curl \
  -H "X-Catalog-Key: YOUR_SECRET" \
  "https://example.com/catalog-api.php"
```

Optional query parameters:

```text
?currency=USD
?gid=3
?pid=12
?available_only=1
```

Example:

```text
https://example.com/catalog-api.php?currency=USD&available_only=1
```

## Security

For production use:

- Keep HTTPS enabled
- Use a strong API token
- Store the token outside the source code
- Restrict access by IP when possible
- Avoid exposing the API token in frontend JavaScript

## License

MIT
