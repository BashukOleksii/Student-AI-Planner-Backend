<?php

namespace App\Services\Showcase;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class ShowcaseDatabase
{
    public function inspect(): array
    {
        $db = DB::connection();
        $connection = $db->getName();
        $broker = config('auth.passwords.'.config('auth.defaults.passwords'));
        if ($db->getDriverName() !== 'mysql' || $db->getConfig('read') || $db->getConfig('write')
            || $db->transactionLevel() !== 0
            || (new User)->getConnection()->getName() !== $connection
            || (new PersonalAccessToken)->getConnection()->getName() !== $connection
            || config('session.driver') !== 'database'
            || (config('session.connection') ?? $connection) !== $connection
            || ! is_array($broker) || ($broker['driver'] ?? 'database') !== 'database'
            || ($broker['provider'] ?? null) !== 'users' || ($broker['connection'] ?? $connection) !== $connection) {
            throw new RuntimeException('Потрібні одна MySQL connection, database-сесії та database password broker на тій самій connection.');
        }
        $identity = $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS server_uuid, @@hostname AS hostname, @@port AS port', [], false);
        if ($identity->name !== $db->getDatabaseName()) {
            throw new RuntimeException('Фактична БД не збігається з налаштованою.');
        }
        $unsafe = $db->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'", [], false);
        if ($unsafe !== []) {
            throw new RuntimeException('Транзакційна заміна потребує InnoDB для всіх таблиць.');
        }

        return ['database' => $identity->name, 'server_uuid' => $identity->server_uuid,
            'hostname' => $identity->hostname, 'port' => $identity->port,
            'endpoint' => $db->getConfig('unix_socket') ?: $db->getConfig('host')];
    }

    /** Caller holds an owner row lock and the email advisory lock in one transaction. */
    public function delete(User $owner): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Видалення потребує транзакції та блокування власника.');
        }
        $this->protectOtherOwners($owner);
        // Laravel polymorphic types respect morph maps. These records have no owner FK.
        $owner->notifications()->delete();
        PersonalAccessToken::where('tokenable_type', $owner->getMorphClass())->where('tokenable_id', $owner->id)->delete();
        Password::broker()->deleteToken($owner);
        DB::table(config('session.table'))->where('user_id', $owner->id)->delete();

        // Permanent account purge, including soft-deleted rows. Restrictive schedule FKs
        // require children before catalogs; ordinary CRUD deletion preserves history.
        foreach (['automatic_session_reminders', 'reminders', 'study_sessions', 'tasks', 'lessons',
            'schedule_date_exception_lessons', 'semester_schedule_suppressed_slots', 'schedule_date_exceptions',
            'weekly_lesson_rules', 'semester_schedules', 'semester_schedule_imports', 'schedule_import_batches',
            'academic_periods', 'subjects', 'teachers', 'education_institutions'] as $table) {
            DB::table($table)->where('user_id', $owner->id)->delete();
        }
        if (! $owner->delete()) {
            throw new RuntimeException('Не вдалося видалити попередній акаунт.');
        }
        // Fail closed on new or unexpected owner tables instead of leaving old data.
        $tables = DB::select("SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'", [], false);
        foreach ($tables as $table) {
            if (DB::table($table->name)->where('user_id', $owner->id)->exists()) {
                throw new RuntimeException('Не всі залежності попереднього акаунта очищено: '.$table->name);
            }
        }
    }

    private function protectOtherOwners(User $owner): void
    {
        $columns = collect(DB::select('SELECT TABLE_NAME AS name, COLUMN_NAME AS field FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()', [], false))
            ->groupBy('name')->map(fn ($rows) => $rows->pluck('field')->all());
        $ownership = ['users' => DB::table('users')->selectRaw('id, id AS user_id')];
        foreach ($columns as $table => $fields) {
            if (in_array('user_id', $fields, true)) {
                // Lock parent rows before checking FKs, preventing new cross-owner
                // references from slipping between the check and ON DELETE SET NULL.
                DB::table($table)->where('user_id', $owner->id)->lockForUpdate()->get();
                if (in_array('id', $fields, true)) {
                    $ownership[$table] = DB::table($table)->select(['id', 'user_id']);
                }
            }
        }
        foreach (['subtasks' => ['tasks', 'task_id'], 'schedule_import_rows' => ['schedule_import_batches', 'schedule_import_batch_id'],
            'ai_messages' => ['ai_conversations', 'conversation_id'], 'ai_conversation_turns' => ['ai_conversations', 'conversation_id'],
            'ai_pending_actions' => ['ai_conversations', 'conversation_id']] as $table => [$parent, $key]) {
            $ownership[$table] = DB::table($table.' AS entry')->join($parent.' AS owner', 'owner.id', '=', 'entry.'.$key)
                ->select(['entry.id', 'owner.user_id']);
        }
        $ownership['notifications'] = DB::table('notifications')->where('notifiable_type', $owner->getMorphClass())
            ->selectRaw('id, notifiable_id AS user_id');
        $references = DB::select("SELECT TABLE_NAME AS child, COLUMN_NAME AS field, REFERENCED_TABLE_NAME AS parent
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_COLUMN_NAME = 'id' AND COLUMN_NAME <> 'user_id'", [], false);
        foreach ($references as $reference) {
            if (! isset($ownership[$reference->child], $ownership[$reference->parent])) {
                continue;
            }
            $foreign = DB::table($reference->child.' AS entry')
                ->joinSub(clone $ownership[$reference->child], 'origin', 'origin.id', '=', 'entry.id')
                ->joinSub(clone $ownership[$reference->parent], 'target', 'target.id', '=', 'entry.'.$reference->field)
                ->where('target.user_id', $owner->id)
                ->where(fn ($query) => $query->where('origin.user_id', '<>', $owner->id)->orWhereNull('origin.user_id'))
                ->exists();
            if ($foreign) {
                throw new RuntimeException('Відмова: записи іншого власника посилаються на дані цього акаунта ('.$reference->child.'.'.$reference->field.').');
            }
        }
    }
}
