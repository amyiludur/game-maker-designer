<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BotProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Every game needs an opponent to play against on day one, and a random one needs no
        // authoring. Idempotent, so seeding twice is not two opponents.
        BotProfile::ensureRandom();
    }
}
