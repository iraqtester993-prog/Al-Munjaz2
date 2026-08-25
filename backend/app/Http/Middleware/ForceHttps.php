<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing']) && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 302);
        }

        return $next($request);
    }
}
