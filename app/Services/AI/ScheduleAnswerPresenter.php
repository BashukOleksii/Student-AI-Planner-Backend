<?php

namespace App\Services\AI;

use App\Services\AI\Tools\EffectiveScheduleReadService;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/** Presentation only: never selects, recalculates or changes calendar data. */
class ScheduleAnswerPresenter
{
    private const FAILURE = 'Не вдалося отримати розклад на цю дату. Спробуйте ще раз пізніше.';

    /** Deliberately narrow, self-contained intents; no inference from model prose or history. */
    public function requestedDate(string $content, string $timezone, ?CarbonImmutable $asOfUtc = null): ?string
    {
        $text = mb_strtolower(trim($content));
        if (! preg_match('/\A(?:покажи (?:мій )?розклад (?:на )?|який (?:у мене )?розклад (?:на )?|show (?:me )?(?:my )?schedule (?:for )?)(сьогодні|завтра|today|tomorrow|\d{4}-\d{2}-\d{2})[?.!]?\z/u', $text, $match)) {
            return null;
        }
        try {
            $local = ($asOfUtc ?? CarbonImmutable::now('UTC'))->setTimezone($timezone);

            return match ($match[1]) {
                'сьогодні', 'today' => $local->toDateString(),
                'завтра', 'tomorrow' => $local->addDay()->toDateString(),
                default => $this->validDate($match[1]) ? $match[1] : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    public function render(array $result, string $date, string $timezone): string
    {
        if (($result['tool'] ?? null) !== 'get_schedule_for_date' || ($result['ok'] ?? null) !== true) {
            return self::FAILURE;
        }
        $data = $result['data'] ?? null;
        $meta = is_array($data) ? ($data['meta'] ?? null) : null;
        $lessons = is_array($data) ? ($data['lessons'] ?? null) : null;
        if (! is_array($meta) || ! is_array($lessons) || ! array_is_list($lessons)
            || ($meta['timezone'] ?? null) !== $timezone || ($meta['start_date'] ?? null) !== $date || ($meta['end_date'] ?? null) !== $date
            || ($meta['limit'] ?? null) !== EffectiveScheduleReadService::LIMIT || ($meta['returned'] ?? null) !== count($lessons)
            || ! is_int($meta['total'] ?? null) || $meta['total'] < count($lessons)
            || count($lessons) !== min($meta['total'], EffectiveScheduleReadService::LIMIT)
            || ($meta['truncated'] ?? null) !== ($meta['total'] > count($lessons))) {
            return self::FAILURE;
        }
        try {
            new DateTimeZone($timezone);
            if (! $this->validDate($date)) {
                return self::FAILURE;
            }
            $heading = 'Розклад на '.CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('d.m.Y').' ('.$timezone.').';
            if ($lessons === []) {
                return $heading."\nНа цю дату занять немає.";
            }
            $types = ['lecture' => 'Лекція', 'laboratory' => 'Лабораторна робота', 'practical' => 'Практичне заняття',
                'seminar' => 'Семінар', 'consultation' => 'Консультація', 'exam' => 'Іспит', 'other' => 'Інше заняття'];
            $lines = [$heading];
            foreach ($lessons as $lesson) {
                if (! is_array($lesson) || ! $this->relation($lesson['subject'] ?? false) || ! $this->relation($lesson['teacher'] ?? false)
                    || ! array_key_exists('room', $lesson) || ! ($lesson['room'] === null || $this->text($lesson['room']))
                    || ! array_key_exists('type', $lesson) || ! ($lesson['type'] === null || (is_string($lesson['type']) && isset($types[$lesson['type']])))
                    || ! is_string($lesson['starts_at'] ?? null) || ! is_string($lesson['ends_at'] ?? null)
                    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $lesson['starts_at'])
                    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $lesson['ends_at'])) {
                    return self::FAILURE;
                }
                $start = $this->instant($lesson['starts_at'])->setTimezone($timezone);
                $end = $this->instant($lesson['ends_at'])->setTimezone($timezone);
                // The authoritative read includes lessons overlapping this local date.
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
                if ($end <= $start || $start >= $day->addDay() || $end <= $day) {
                    return self::FAILURE;
                }
                $clock = fn (CarbonImmutable $time) => $time->format(($time->toDateString() === $date ? '' : 'd.m.Y ')
                    .($time->second === 0 ? 'H:i' : 'H:i:s'));
                $lines[] = $clock($start).'–'.$clock($end).' — '.($lesson['subject']['name'] ?? 'Предмет не вказано')
                    .' · '.($lesson['type'] === null ? 'Тип заняття не вказано' : $types[$lesson['type']])
                    .($lesson['teacher'] === null ? '' : ' · Викладач: '.$lesson['teacher']['name'])
                    .($lesson['room'] === null ? '' : ' · Аудиторія: '.$lesson['room']);
            }
            if ($meta['truncated']) {
                $lines[] = 'Показано перші '.count($lessons).' із '.$meta['total'].' занять. Повний розклад перегляньте в календарі.';
            }
            $answer = implode("\n", $lines);

            return mb_strlen($answer) <= 16000 ? $answer : self::FAILURE;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    private function validDate(string $date): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
            && (int) $parts[1] >= 1000
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function instant(string $value): CarbonImmutable
    {
        $wire = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        if (! preg_match('/[+-](?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $wire)) {
            throw new \UnexpectedValueException('Invalid schedule timestamp.');
        }
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $wire);
        if ($time === false || $time->format('Y-m-d\TH:i:sP') !== $wire
            || ! $this->validDate(substr($wire, 0, 10))) {
            throw new \UnexpectedValueException('Invalid schedule timestamp.');
        }

        return $time;
    }

    private function relation(mixed $value): bool
    {
        return $value === null || (is_array($value) && is_int($value['id'] ?? null) && $value['id'] > 0 && $this->text($value['name'] ?? null));
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8');
    }
}
