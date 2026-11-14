<?php

use App\Exceptions\AiAgentException;
use App\Exceptions\RecoverySuggestionException;
use App\Exceptions\ReminderConflictException;
use App\Exceptions\ReminderIntegrityException;
use App\Exceptions\StudyPlanConflictException;
use App\Exceptions\StudySessionConflictException;
use App\Http\Middleware\ObserveAiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: ['127.0.0.1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT,
        );
        $middleware->statefulApi();
        $middleware->api(prepend: [ObserveAiRequest::class]);
        // Validate exact UTC wire formats instead of silently trimming timestamps.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/ai/*'),
            fn (Request $request) => $request->is('api/statistics/progress', 'api/statistics/trends', 'api/statistics/reports/comparison'),
            fn (Request $request) => $request->is('api/reminders', 'api/reminders/*', 'api/notifications', 'api/notifications/*'),
            'deadline_at',
            fn (Request $request) => $request->is('api/lessons', 'api/lessons/*'),
            fn (Request $request) => $request->isMethod('GET') && $request->is('api/tasks'),
            fn (Request $request) => $request->isMethod('GET') && $request->is('api/planning/free-slots', 'api/planning/workload-advice'),
            fn (Request $request) => $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/planning/recovery/*', 'api/planning/recovery-suggestions', 'api/planning/recovery-suggestions/*', 'api/study-sessions', 'api/study-sessions/*'),
        ]);
        // Subject color and Task deadlines distinguish null from an invalid empty string.
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/ai/*'),
            fn (Request $request) => $request->is('api/reminders', 'api/reminders/*', 'api/notifications', 'api/notifications/*'),
            fn (Request $request) => $request->is('api/subjects', 'api/subjects/*'),
            fn (Request $request) => $request->is('api/tasks', 'api/tasks/*'),
            fn (Request $request) => $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/planning/recovery/*', 'api/planning/recovery-suggestions', 'api/planning/recovery-suggestions/*', 'api/study-sessions', 'api/study-sessions/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token']);
        // Password/SMTP/SQL exceptions may contain secrets; only fixed diagnostics are allowed.
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('api/profile/email/*')) {
                try {
                    Log::warning('email_verification.failure');
                } catch (Throwable) {
                }

                return false;
            }
            if (request()->is('api/profile/password', 'api/auth/forgot-password', 'api/auth/reset-password')) {
                try {
                    Log::warning('password.failure');
                } catch (Throwable) {
                }

                return false;
            }
        });
        // Never let default exception reporting persist AI prompts, SQL bindings or private payloads.
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('api/ai/*')) {
                $code = match ($exception instanceof AiAgentException ? $exception->status() : 500) {
                    409 => 'conflict', 410 => 'expired', 422 => 'invalid_request',
                    502 => 'provider_error', 503 => 'ai_unavailable', default => 'persistence_error',
                };
                try {
                    Log::warning('ai.failure', ['code' => $code]);
                } catch (Throwable) {
                    // A logging outage must not replace the original safe response.
                }

                return false;
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/profile/email/*')) {
                if ($exception instanceof HttpResponseException) {
                    return $exception->getResponse();
                }
                if ($exception instanceof ValidationException) {
                    return response()->json(['message' => 'Перевірте дані запиту.', 'errors' => $exception->errors()], 422);
                }
                $status = $exception instanceof AuthenticationException ? 401
                    : ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500 ? $exception->getStatusCode() : 503);
                $message = match ($status) {
                    401 => 'Увійдіть у свій акаунт для підтвердження email-адреси.',
                    403 => 'Посилання недійсне, прострочене або не відповідає поточній email-адресі. Запросіть новий лист.',
                    404 => 'Сторінку підтвердження не знайдено.',
                    405 => 'Підтвердьте email-адресу кнопкою на сторінці підтвердження.',
                    419 => 'Сесія застаріла. Оновіть сторінку та повторіть дію.',
                    429 => 'Забагато спроб. Спробуйте ще раз пізніше.',
                    default => 'Підтвердження email-адреси тимчасово недоступне. Спробуйте пізніше.',
                };

                return response()->json(['message' => $message], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            }
            if ($request->is('api/planning/recovery-suggestions', 'api/planning/recovery-suggestions/*', 'api/planning/recovery/diagnostics')) {
                if ($exception instanceof RecoverySuggestionException) {
                    return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status());
                }
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'Recovery suggestions could not be processed.', 'code' => 'recovery_failed'], 500);
            }

            if ($request->is('api/academic-calendar/*')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }
                if ($exception instanceof InvalidArgumentException) {
                    return response()->json(['message' => 'The saved academic calendar configuration is inconsistent.'], 409);
                }

                return response()->json(['message' => 'The academic calendar could not be processed.'], 500);
            }

            if ($request->is('api/semester-schedule-imports', 'api/semester-schedule-imports/*')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'The semester schedule import could not be processed.'], 500);
            }
            if ($request->is('api/semester-schedules', 'api/semester-schedules/*')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }
                if ($exception instanceof InvalidArgumentException) {
                    return response()->json(['message' => 'The saved semester schedule configuration is inconsistent.'], 409);
                }

                return response()->json(['message' => 'The semester schedule could not be processed.'], 500);
            }
            if ($request->is('api/planning/workload-advice')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'The workload advice could not be processed.'], 500);
            }
            if ($request->is('api/profile/password', 'api/auth/forgot-password', 'api/auth/reset-password')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException
                    || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)) {
                    return null;
                }

                return response()->json(['message' => 'Password management is temporarily unavailable.'], 503);
            }
            if ($request->is('api/ai/*')) {
                if ($exception instanceof AiAgentException) {
                    return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status());
                }
                if ($exception instanceof ValidationException) {
                    // Only AI HTTP presentation is localized; field keys, validators and manual REST stay unchanged.
                    $labels = [
                        'content' => 'Введіть змістовне повідомлення до 4000 символів.',
                        'idempotency_key' => 'Надішліть запит із чинним ключем повторення.',
                        'request' => 'Запит має містити лише дозволені поля у підтримуваному форматі.',
                        'page' => 'Укажіть коректний номер сторінки.',
                        'per_page' => 'Кількість записів на сторінці має бути від 1 до 50.',
                        'through_id' => 'Оновіть історію та повторіть завантаження.',
                        'accept_partial' => 'Підтвердьте згоду на частковий результат.',
                        'accept_unplaced_cancellation' => 'Підтвердьте згоду на скасування сесій без заміни.',
                        'accept_split_replacements' => 'Підтвердьте згоду на поділ сесії.',
                        'completion_token' => 'Огляд завершення змінився. Перевірте наслідки ще раз.',
                    ];
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$field] = array_fill(0, count($messages), $labels[$field] ?? 'Перевірте значення цього поля.');
                    }

                    return response()->json(['message' => 'Перевірте дані запиту.', 'errors' => $errors], $exception->status);
                }
                if ($exception instanceof AuthenticationException) {
                    return response()->json(['message' => 'Увійдіть до облікового запису, щоб скористатися AI-помічником.'], 401);
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    $message = match ($exception->getStatusCode()) {
                        404 => 'Розмову або дію не знайдено.',
                        419 => 'Сеанс захисту запиту завершився. Оновіть сторінку та повторіть дію.',
                        429 => 'Забагато запитів. Зачекайте й спробуйте ще раз.',
                        403 => 'Доступ до цієї дії заборонено.',
                        405 => 'Цей спосіб запиту не підтримується.',
                        default => 'Не вдалося виконати запит AI-чату.',
                    };

                    return response()->json(['message' => $message], $exception->getStatusCode(), $exception->getHeaders());
                }

                return response()->json(['message' => 'Не вдалося опрацювати розмову з AI-помічником.', 'code' => 'persistence_error'], 500);
            }
            if ($request->is('api/statistics/progress', 'api/statistics/trends', 'api/statistics/reports/comparison')) {
                if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                    return null;
                }
                if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                    return $exception->getStatusCode() === 404 ? response()->json(['message' => 'Not found.'], 404) : null;
                }

                return response()->json(['message' => 'The progress statistics could not be processed.'], 500);
            }
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
            if ($exception instanceof ConflictHttpException && $request->is('api/tasks/*')) {
                return response()->json(['message' => $exception->getMessage()], 409);
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
            if (! $request->is('api/planning/study-plans/*', 'api/planning/replanning/*', 'api/planning/recovery/*', 'api/planning/recovery-suggestions', 'api/planning/recovery-suggestions/*', 'api/study-sessions', 'api/study-sessions/*')) {
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
