<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed public diagnostics, never source contents, SQL or encrypted tokens. */
class RecoverySuggestionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'analysis_limit_exceeded' => 'Обсяг даних перевищує межі автоматичного аналізу відновлення. Скористайтеся ручним плануванням для меншого обсягу роботи.',
            'suggestion_expired' => 'Строк цього огляду відновлення минув. Створіть новий огляд.',
            'suggestion_stale' => 'Вихідні дані відновлення змінилися. Створіть новий огляд.',
            'suggestion_resolved' => 'Рішення за цим оглядом відновлення вже ухвалено.',
            'decision_required' => 'Перед застосуванням цього огляду потрібно вказати результат сесії або виправити налаштування планування.',
            'no_recovery_needed' => 'Зараз рекомендація з відновлення не потрібна.',
            default => 'Не вдалося опрацювати рекомендації з відновлення.',
        });
    }

    public function status(): int
    {
        return match ($this->errorCode) {
            'suggestion_expired' => 410,
            'suggestion_stale', 'suggestion_resolved', 'no_recovery_needed' => 409,
            'analysis_limit_exceeded', 'decision_required' => 422,
            default => 500,
        };
    }
}
