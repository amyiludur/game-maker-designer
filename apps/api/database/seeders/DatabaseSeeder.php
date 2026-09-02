<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BotProfile;
use Illuminate\Database\Seeder;

/**
 * Deliberately without Laravel's `WithoutModelEvents`.
 *
 * Every model here mints its own primary key from a `creating` hook (`HasUuidV7`), and
 * muting model events mutes that too — seeding then inserts a null id and Postgres rejects
 * the row. The trait's approach is worth keeping (an id can be minted before the write), so
 * the seeder is what gives way.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Every game needs an opponent to play against on day one, and a random one needs no
        // authoring. Idempotent, so seeding twice is not two opponents.
        BotProfile::ensureRandom();
    }
}
