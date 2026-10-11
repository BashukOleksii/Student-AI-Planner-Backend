<?php

namespace App\Services\Imports;

use InvalidArgumentException;

class ScheduleImportFingerprint
{
    /** Fixed V1 identity: resolved Subject ID and whole-second UTC interval only. */
    public function generate(int $subjectId, string $startsAtUtc, string $endsAtUtc): string
    {
        if ($subjectId <= 0) {
            throw new InvalidArgumentException('Subject ID must be a positive integer.');
        }
        $this->validateInstant($startsAtUtc);
        $this->validateInstant($endsAtUtc);
        // Validated, fixed-width UTC strings sort in instant order.
        if ($startsAtUtc >= $endsAtUtc) {
            throw new InvalidArgumentException('UTC start must precede end.');
        }

        $canonical = json_encode([(string) $subjectId, $startsAtUtc, $endsAtUtc],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical, false);
    }

    private function validateInstant(string $instant): void
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z\z/', $instant, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Expected a valid whole-second UTC YYYY-MM-DDTHH:MM:SSZ instant.');
        }
    }
}
