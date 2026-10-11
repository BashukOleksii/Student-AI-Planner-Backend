<?php

namespace Tests\Unit;

use App\Enums\LessonType;
use App\Services\Imports\ScheduleImportFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleImportFingerprintTest extends TestCase
{
    private const START = '2026-10-05T06:00:00Z';

    private const END = '2026-10-05T07:30:00Z';

    private const VERIFIED = 'aaef02b0a645f0b15b7583ce6b0f21ece2ab2f8ea3d7a8dd2f0c1e6104ed8806';

    public function test_exact_documented_canonical_example_and_repeatability(): void
    {
        $generator = new ScheduleImportFingerprint;
        $canonical = '["42","2026-10-05T06:00:00Z","2026-10-05T07:30:00Z"]';

        $this->assertSame(self::VERIFIED, hash('sha256', $canonical, false));
        $this->assertSame(self::VERIFIED, $generator->generate(42, self::START, self::END));
        $this->assertSame(self::VERIFIED, $generator->generate(42, self::START, self::END));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $generator->generate(42, self::START, self::END));
    }

    public function test_server_default_timezone_is_irrelevant(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['Pacific/Apia', 'Europe/Kyiv', 'America/Los_Angeles'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame(self::VERIFIED, (new ScheduleImportFingerprint)->generate(42, self::START, self::END));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_large_integer_id_is_serialized_without_float_conversion(): void
    {
        $canonical = '["'.PHP_INT_MAX.'","'.self::START.'","'.self::END.'"]';

        $this->assertSame(hash('sha256', $canonical, false), (new ScheduleImportFingerprint)->generate(PHP_INT_MAX, self::START, self::END));
    }

    #[DataProvider('identityChanges')]
    public function test_changing_any_identity_component_changes_fingerprint(int $id, string $start, string $end): void
    {
        $this->assertNotSame(self::VERIFIED, (new ScheduleImportFingerprint)->generate($id, $start, $end));
    }

    public static function identityChanges(): array
    {
        return [[43, self::START, self::END], [42, '2026-10-05T06:00:01Z', self::END], [42, self::START, '2026-10-05T07:30:01Z']];
    }

    #[DataProvider('excludedMetadata')]
    public function test_metadata_is_excluded_from_the_three_argument_api(string $field, mixed $value): void
    {
        $candidate = [
            'subject_id' => 42, 'starts_at_utc' => self::START, 'ends_at_utc' => self::END,
            'subject_name' => 'Old title', 'subject_code' => 'OLD', 'teacher_id' => 7,
            'room' => '123', 'type' => LessonType::Other, 'group' => 'ПІ-231', 'source' => ['range' => 'D3:F4'],
        ];
        $candidate[$field] = $value;

        $this->assertSame(self::VERIFIED, (new ScheduleImportFingerprint)->generate(
            $candidate['subject_id'], $candidate['starts_at_utc'], $candidate['ends_at_utc'],
        ));
    }

    public static function excludedMetadata(): array
    {
        return [
            ['subject_name', 'Renamed title'], ['subject_code', 'NEW'], ['teacher_id', 8], ['teacher_name', 'Other teacher'],
            ['room', '456'], ['type', LessonType::Lecture], ['group', 'Other group'],
            ['source', ['range' => 'D19:F20']], ['filename', 'other.xlsx'], ['import_batch_id', 99],
        ];
    }

    #[DataProvider('invalidSubjectIds')]
    public function test_non_positive_subject_id_is_rejected(int $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Subject ID must be a positive integer');
        (new ScheduleImportFingerprint)->generate($id, self::START, self::END);
    }

    public static function invalidSubjectIds(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    #[DataProvider('invalidInstants')]
    public function test_invalid_instant_is_rejected_in_either_endpoint(string $invalid, bool $asStart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a valid whole-second UTC');
        (new ScheduleImportFingerprint)->generate(42, $asStart ? $invalid : self::START, $asStart ? self::END : $invalid);
    }

    public static function invalidInstants(): array
    {
        $invalid = [
            '', 'not-a-date', '2026-10-05 06:00:00', '2026-10-05T06:00:00', '2026-10-05T06:00:00z',
            '2026-10-05T06:00:00+00:00', '2026-10-05T09:00:00+03:00', '2026-10-05T06:00:00.000Z',
            ' 2026-10-05T06:00:00Z', '2026-10-05T06:00:00Z ', "2026-10-05T06:00:00Z\n",
            "\u{FEFF}2026-10-05T06:00:00Z", '2026-02-29T06:00:00Z', '2026-02-30T06:00:00Z',
            '2026-13-05T06:00:00Z', '2026-10-00T06:00:00Z', '0000-10-05T06:00:00Z',
            '2026-10-05T24:00:00Z', '2026-10-05T06:60:00Z', '2026-10-05T06:00:60Z', '2026-1-05T06:00:00Z',
        ];
        $cases = [];
        foreach ($invalid as $index => $instant) {
            $cases['start '.$index] = [$instant, true];
            $cases['end '.$index] = [$instant, false];
        }

        return $cases;
    }

    #[DataProvider('invalidIntervals')]
    public function test_equal_or_reversed_interval_is_rejected(string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UTC start must precede end');
        (new ScheduleImportFingerprint)->generate(42, self::START, $end);
    }

    public static function invalidIntervals(): array
    {
        return [[self::START], ['2026-10-05T05:59:59Z']];
    }

    public function test_real_leap_day_and_interval_crossing_year_boundary_are_supported(): void
    {
        $generator = new ScheduleImportFingerprint;

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $generator->generate(1, '2028-02-29T06:00:00Z', '2028-02-29T07:30:00Z'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $generator->generate(1, '2026-12-31T23:30:00Z', '2027-01-01T01:00:00Z'));
    }
}
