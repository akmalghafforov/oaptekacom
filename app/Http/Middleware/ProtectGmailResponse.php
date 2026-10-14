<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectGmailResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('admin.gmail.callback') && ! $request->user()) {
            $request->session()->forget(['gmail_oauth', 'url.intended']);
            $response = redirect()->route('login');
        } else {
            $response = $next($request);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
