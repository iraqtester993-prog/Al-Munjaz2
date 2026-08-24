<?php

declare(strict_types=1);

// cPanel primary-domain fallback. Prefer setting the domain document root to
// the backend/public directory directly; update this path only if a fallback
// entry point is required by the hosting provider.
require __DIR__.'/../almunjaz/backend/public/index.php';
