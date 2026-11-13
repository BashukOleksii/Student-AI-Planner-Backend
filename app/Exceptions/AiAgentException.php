<?php

namespace App\Exceptions;

use RuntimeException;

/** Only fixed public errors; never constructed from upstream messages. */
class AiAgentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'multiple_actions' => 'За одне повідомлення можна запропонувати лише одну зміну.',
            'action_expired' => 'Строк пропозиції минув. Зміни не виконано.',
            'action_executed' => 'Виконану дію вже не можна відхилити.',
            'action_stale' => 'Дані змінилися. Попросіть нову пропозицію.',
            'action_conflict' => 'Запропоновану зміну плану більше неможливо виконати. Попросіть нову пропозицію.',
            'action_token_invalid' => 'Огляд плану більше не дійсний або його строк минув. Попросіть нову пропозицію.',
            'action_validation_failed' => 'Ці зміни більше не дозволені. Перевірте дані та попросіть нову пропозицію.',
            'ai_unavailable' => 'AI-помічник зараз недоступний. Спробуйте пізніше.',
            'provider_error' => 'AI-помічник не зміг завершити відповідь. Перевірте історію перед повторенням запиту.',
            'invalid_provider_response' => 'AI-помічник повернув некоректну відповідь. Перевірте історію перед повторенням запиту.',
            'conversation_busy' => 'Попередній запит у цій розмові ще обробляється. Зачекайте та оновіть історію.',
            'turn_expired' => 'Строк обробки запиту минув. Повторіть той самий запит зі збереженим ключем повторення.',
            'idempotency_conflict' => 'Цей ключ повторення вже використано для іншого повідомлення. Перевірте історію.',
            'execution_limit_reached' => 'Запит перевищив ліміт обробки. Уточніть запит або почніть нову розмову.',
            default => 'Не вдалося опрацювати розмову з AI-помічником.',
        });
    }

    public function status(): int
    {
        return match ($this->errorCode) {
            'ai_unavailable' => 503,
            'provider_error', 'invalid_provider_response' => 502,
            'conversation_busy', 'turn_expired', 'idempotency_conflict', 'action_executed', 'action_stale', 'action_conflict' => 409,
            'action_expired' => 410,
            'execution_limit_reached', 'multiple_actions', 'action_validation_failed', 'action_token_invalid' => 422,
            default => 500,
        };
    }
}
