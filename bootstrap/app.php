<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Validate exact UTC wire formats instead of silently trimming timestamps.
        $middleware->trimStrings(except: [
            'deadline_at',
            fn (Request $request) => $request->is('api/lessons', 'api/lessons/*'),
            fn (Request $request) => $request->isMethod('GET') && $request->is('api/tasks'),
        ]);
        // Subject color and Task deadlines distinguish null from an invalid empty string.
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/subjects', 'api/subjects/*'),
            fn (Request $request) => $request->is('api/tasks', 'api/tasks/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
