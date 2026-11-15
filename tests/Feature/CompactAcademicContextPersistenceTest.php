<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactAcademicContextPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('academicModels')]
    public function test_user_relationships_and_cascade_deletes_are_scoped_to_the_owner(string $model, string $relationship): void
    {
        $first = $model::factory()->create();
        $second = $model::factory()->create();
        $firstUser = $first->user;
        $secondUser = $second->user;
        $this->assertNotSame($firstUser->id, $secondUser->id);
        $this->assertSame([$first->id], $firstUser->{$relationship}->modelKeys());
        $this->assertSame([$second->id], $secondUser->{$relationship}->modelKeys());
        $this->assertTrue($first->user->is($firstUser));
        $this->assertTrue($second->user->is($secondUser));
        DB::table('users')->where('id', $firstUser->id)->delete();
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
    }

    public static function academicModels(): array
    {
        return ['institution' => ['App\Models\EducationInstitution', 'educationInstitutions']];
    }

    public static function institutionChildren(): array
    {
        return ['period' => ['App\Models\AcademicPeriod', 'academicPeriods']];
    }

    #[DataProvider('institutionChildren')]
    public function test_optional_institution_relationships_and_set_null_delete(string $model, string $relationship): void
    {
        $institution = EducationInstitution::factory()->create();
        $otherInstitution = EducationInstitution::factory()->for($institution->user)->create();
        $record = $model::factory()->for($institution->user)->for($institution)->create();
        $other = $model::factory()->for($institution->user)->for($otherInstitution)->create();
        $unlinked = $model::factory()->for($institution->user)->create();
        $this->assertSame([$record->id], $institution->{$relationship}->modelKeys());
        $this->assertSame([$other->id], $otherInstitution->{$relationship}->modelKeys());
        $this->assertTrue($record->educationInstitution->is($institution));
        $this->assertSame($institution->user_id, $record->user_id);
        $this->assertNull($unlinked->refresh()->education_institution_id);
        $this->assertNull($unlinked->educationInstitution);
        DB::table('education_institutions')->where('id', $institution->id)->delete();
        $this->assertModelExists($record);
        $this->assertNull($record->refresh()->education_institution_id);
        $this->assertNull($record->educationInstitution);
        $this->assertSame($otherInstitution->id, $other->refresh()->education_institution_id);
        $this->assertModelExists($institution->user);
    }

    public function test_user_deletion_cascades_through_linked_academic_records(): void
    {
        $institution = EducationInstitution::factory()->create();
        $records = [];
        foreach (self::institutionChildren() as [$model]) {
            $records[] = $model::factory()->for($institution->user)->for($institution)->create();
        }
        DB::table('users')->where('id', $institution->user_id)->delete();
        $this->assertModelMissing($institution);
        foreach ($records as $record) {
            $this->assertModelMissing($record);
        }
    }

    #[DataProvider('uniqueModels')]
    public function test_approved_unique_keys_are_scoped_to_one_user(string $model, array $values, string $constraint): void
    {
        $record = $model::factory()->create($values);
        $anotherOwner = $model::factory()->create($values);
        $this->assertNotSame($record->user_id, $anotherOwner->user_id);
        $this->assertModelExists($anotherOwner);
        $this->assertMysqlRejects(fn () => DB::table($record->getTable())->insert(['user_id' => $record->user_id, ...$values]), 1062, $constraint);
    }

    public static function uniqueModels(): array
    {
        return ['institution' => ['App\Models\EducationInstitution', ['name' => 'Test institution'], 'education_institutions_user_id_name_unique']];
    }

    private function assertMysqlRejects(callable $operation, int $error, string $constraint): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
            $this->assertStringContainsString($constraint, $exception->getMessage());

            return;
        }
        $this->fail('MySQL accepted a record that should violate a database constraint.');
    }
}
