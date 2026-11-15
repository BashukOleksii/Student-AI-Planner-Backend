<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesHistoricalUsers
{
    private function historicalUser(array $attributes = []): User
    {
        $values = array_intersect_key(User::factory()->raw($attributes), array_flip(Schema::getColumnListing('users')));
        $id = DB::table('users')->insertGetId($values);

        return User::query()->findOrFail($id);
    }
}
