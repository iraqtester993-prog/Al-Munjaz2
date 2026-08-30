<?php

declare(strict_types=1);

// Deployment entrypoint for the active shared release. This file belongs in
// both public_html/mobile and public_html/admin when cPanel cannot point the
// two subdomains directly at Laravel's public directory.  Point the
// `/home/ourqiq/releases/current` symlink at the fully uploaded release;
// this keeps PHP, the Vite manifest, and the `build` symlink on one version
// without editing this document-root file for every deployment.
$publicRoot = realpath('/home/ourqiq/releases/current/backend/public');

if ($publicRoot === false || ! is_file($publicRoot.DIRECTORY_SEPARATOR.'index.php')) {
    http_response_code(500);
    exit('Application public directory is not configured.');
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
        header(str_starts_with($relativePath, 'build/assets/') ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: public, max-age=3600');
        if ($method === 'GET') {
            readfile($candidate);
        }
        exit;
    }
}

require $publicRoot.DIRECTORY_SEPARATOR.'index.php';
