<?php

declare(strict_types=1);

/*
 * cPanel entrypoint for the isolated staging application.
 *
 * Deploy one copy in public_html/staging and another in
 * public_html/staging-admin. The database values are intentionally supplied
 * by cPanel at deploy time; never commit production or staging secrets here.
 */
$publicRoot = realpath('/home/ourqiq/releases/almunjaz-8de339e/backend/public');

if ($publicRoot === false || ! is_file($publicRoot.DIRECTORY_SEPARATOR.'index.php')) {
    http_response_code(500);
    exit('Staging application public directory is not configured.');
}

$stagingEnvironment = [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => 'false',
    'APP_URL' => '__STAGING_APP_URL__',
    'APP_CONFIG_CACHE' => '/home/ourqiq/releases/almunjaz-8de339e/backend/bootstrap/cache/config-staging.php',
    'PRODUCT_DOMAIN' => 'our-qiq.com',
    'PRODUCT_MOBILE_HOST' => 'staging.our-qiq.com',
    'PRODUCT_ADMIN_HOST' => 'staging-admin.our-qiq.com',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'ourqiq_almunjaz_staging',
    'DB_USERNAME' => 'ourqiq_almunjaz_stg',
    'DB_PASSWORD' => '__STAGING_DB_PASSWORD__',
    'SESSION_COOKIE' => 'almunjaz_staging_session',
    'SESSION_DOMAIN' => '',
    'CACHE_PREFIX' => 'almunjaz_staging_',
    'PWA_VERSION' => 'staging-v1',
];

foreach ($stagingEnvironment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$relativePath = ltrim(str_replace('\\', '/', $requestPath), '/');
$isDynamicPwaAlias = in_array($relativePath, ['manifest.json', 'sw.js'], true);
$allowedExtensions = ['avif', 'css', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map', 'pdf', 'png', 'svg', 'txt', 'webp', 'woff', 'woff2', 'ttf'];

if (! $isDynamicPwaAlias && in_array($method, ['GET', 'HEAD'], true)) {
    $candidate = realpath($publicRoot.DIRECTORY_SEPARATOR.$relativePath);
    $storageRoot = realpath($publicRoot.DIRECTORY_SEPARATOR.'storage');
    $isInsidePublicRoot = $candidate !== false && str_starts_with($candidate, $publicRoot.DIRECTORY_SEPARATOR);
    $isInsideStorageRoot = $storageRoot !== false && $candidate !== false && str_starts_with($candidate, $storageRoot.DIRECTORY_SEPARATOR);
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    if ($candidate !== false && is_file($candidate) && ($isInsidePublicRoot || $isInsideStorageRoot) && in_array($extension, $allowedExtensions, true)) {
        $mimeTypes = [
            'avif' => 'image/avif', 'css' => 'text/css; charset=utf-8', 'ico' => 'image/x-icon',
            'js' => 'application/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
            'map' => 'application/json; charset=utf-8', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        $mime = $mimeTypes[$extension] ?? (function_exists('mime_content_type') ? mime_content_type($candidate) : null) ?: 'application/octet-stream';
        header('Content-Type: '.$mime);
        header('Content-Length: '.(string) filesize($candidate));
        header('X-Content-Type-Options: nosniff');
        header(str_starts_with($relativePath, 'build/assets/') ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if ($method === 'GET') {
            readfile($candidate);
        }
        exit;
    }
}

require $publicRoot.DIRECTORY_SEPARATOR.'index.php';
