<?php

use App\Http\Middleware\UnescapedUnicodeJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [UnescapedUnicodeJson::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // One Arabic message for a missing permission (D-094): the `can:` middleware would send Laravel's English
        // sentence, and a bare abort(403) an empty one. A refusal that says why keeps its own message.
        $exceptions->render(function (HttpException $e, Request $request) {
            $generic = $e->getStatusCode() === 403
                && in_array($e->getMessage(), ['', 'This action is unauthorized.', __('This action is unauthorized.')], true);

            return $generic && ($request->is('api/*') || $request->expectsJson())
                ? response()->json(['message' => __('auth.forbidden')], 403)
                : null;
        });
    })->create();
