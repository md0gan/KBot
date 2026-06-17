<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckInstalled
{
    /**
     * Uygulama kurulu degilse tum istekleri /install'a yonlendirir.
     * Kuruluysa /install'a erisimi engeller.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $installed = file_exists(storage_path('app/installed.lock'));
        $onInstall = $request->is('install', 'install/*');

        if (! $installed && ! $onInstall && ! $request->is('up')) {
            return redirect('/install');
        }

        if ($installed && $onInstall) {
            return redirect('/');
        }

        return $next($request);
    }
}
