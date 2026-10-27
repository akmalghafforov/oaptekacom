<?php

use App\Http\Middleware\EnforceLoginSessionLifetime;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\ProtectGmailResponse;
use App\Http\Middleware\ResolveAccountMode;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ProtectGmailResponse::class);
        $middleware->appendToPriorityList(StartSession::class, EnforceLoginSessionLifetime::class);
        $middleware->web(append: [EnforceLoginSessionLifetime::class, ResolveAccountMode::class]);
        $middleware->alias(['active' => EnsureAccountIsActive::class, 'role' => EnsureRole::class, 'two-factor-confirmed' => EnsureTwoFactorConfirmed::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('admin/gmail', 'admin/gmail/*') || request()->has('gmail_draft')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
