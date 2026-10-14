<?php

use App\Exceptions\ReminderConflictException;
use App\Exceptions\ReminderIntegrityException;
use App\Exceptions\StudyPlanConflictException;
use App\Exceptions\StudySessionConflictException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            fn (Request $request) => $request->is('api/reminders', 'api/reminders/*', 'api/notifications', 'api/notifications/*'),
            'deadline_at',
            fn (Request $request) => $request->is('api/lessons', 'api/lessons/*'),
            fn (Request $request) => $request->isMethod('GET') && $request->is('api/tasks'),
            fn (Request $request) => $request->isMethod('GET') && $request->is('api/planning/free-slots'),
            fn (Request $request) => $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/study-sessions', 'api/study-sessions/*'),
        ]);
        // Subject color and Task deadlines distinguish null from an invalid empty string.
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/reminders', 'api/reminders/*', 'api/notifications', 'api/notifications/*'),
            fn (Request $request) => $request->is('api/subjects', 'api/subjects/*'),
            fn (Request $request) => $request->is('api/tasks', 'api/tasks/*'),
            fn (Request $request) => $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/study-sessions', 'api/study-sessions/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/notifications', 'api/notifications/*')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'The notification could not be processed.'], 500);
            }
            if ($exception instanceof ReminderIntegrityException && $request->is('api/tasks/*')) {
                return response()->json(['message' => 'The reminder dependencies could not be processed.'], 500);
            }
            if ($request->is('api/reminders', 'api/reminders/*')) {
                if ($exception instanceof ReminderConflictException) {
                    return response()->json(['message' => $exception->getMessage()], 409);
                }
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'The reminder could not be processed.'], 500);
            }
            if (! $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/study-sessions', 'api/study-sessions/*')) {
                return null;
            }
            if ($exception instanceof StudyPlanConflictException || $exception instanceof StudySessionConflictException) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }
            if ($request->is('api/planning/replanning/*') && $exception instanceof UnexpectedValueException) {
                return response()->json(['message' => 'The replanning preview could not be processed.', 'classification' => 'invalid_source'], 500);
            }
            if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                return null;
            }
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
            }

            // Source corruption, SQL failures and internal bugs never expose details, even with APP_DEBUG.
            return response()->json(['message' => 'The study plan could not be processed.'], 500);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
