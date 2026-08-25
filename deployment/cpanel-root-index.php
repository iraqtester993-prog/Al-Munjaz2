<?php

declare(strict_types=1);

/*
 * cPanel document-root fallback
 * -----------------------------
 *
 * Preferred deployment: point the domain directly at backend/public.
 *
 * Use this file only when the host refuses a custom document root. Copy it to
 * the domain's public root as index.php, update the release path below, and
 * copy backend/public/.htaccess beside it. The code safely forwards public
 * static files (including Vite build assets) to the real Laravel public
 * directory before handing dynamic requests to Laravel. A plain `require`
 * cannot do that and causes the CSS/JS MIME and 404 failures seen on cPanel.
 */

$publicRoot = realpath(__DIR__.'/../almunjaz/backend/public');

if ($publicRoot === false || ! is_file($publicRoot.DIRECTORY_SEPARATOR.'index.php')) {
    http_response_code(500);
    exit('Application public directory is not configured.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$relativePath = ltrim(str_replace('\\', '/', $requestPath), '/');

// These two aliases must reach Laravel so an older installed PWA migrates to
// the current versioned manifest/worker instead of retaining its old cache.
$isDynamicPwaAlias = in_array($relativePath, ['manifest.json', 'sw.js'], true);

$allowedExtensions = [
    'avif', 'css', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map', 'pdf',
    'png', 'svg', 'txt', 'webp', 'woff', 'woff2', 'ttf',
];

if (! $isDynamicPwaAlias && in_array($method, ['GET', 'HEAD'], true)) {
    $candidate = realpath($publicRoot.DIRECTORY_SEPARATOR.$relativePath);
    $storageRoot = realpath($publicRoot.DIRECTORY_SEPARATOR.'storage');
    $allowedRoots = array_filter([$publicRoot, $storageRoot]);
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    $isInsideAllowedRoot = false;

    foreach ($allowedRoots as $root) {
        if (str_starts_with((string) $candidate, $root.DIRECTORY_SEPARATOR)) {
            $isInsideAllowedRoot = true;
            break;
        }
    }

    $isPublicFile = $candidate !== false
        && is_file($candidate)
        && in_array($extension, $allowedExtensions, true)
        && $isInsideAllowedRoot;

    if ($isPublicFile) {
        $mimeTypes = [
            'avif' => 'image/avif', 'css' => 'text/css; charset=utf-8',
            'ico' => 'image/x-icon', 'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        $detectedMime = function_exists('mime_content_type') ? mime_content_type($candidate) : null;
        $mime = $mimeTypes[$extension] ?? $detectedMime ?: 'application/octet-stream';

        header('Content-Type: '.$mime);
        header('Content-Length: '.(string) filesize($candidate));
        header('X-Content-Type-Options: nosniff');
        header(str_starts_with($relativePath, 'build/assets/')
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: public, max-age=3600');

        if ($method === 'GET') {
            readfile($candidate);
        }

        exit;
    }
}

require $publicRoot.DIRECTORY_SEPARATOR.'index.php';
