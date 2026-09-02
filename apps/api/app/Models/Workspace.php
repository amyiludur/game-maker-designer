<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A team's shelf. Everything a designer creates hangs off one of these. */
final class Workspace extends Model
{
    use HasUuidV7;

    protected $fillable = ['name', 'slug', 'owner_id'];

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The workspace with this slug, created if it is not there yet.
     *
     * Until authentication exists (M5) there is nobody to attribute a workspace to, so both
     * ways of getting a game into the platform — the importer and the API — land on the same
     * shelf rather than each inventing one.
     */
    public static function ensure(string $slug): self
    {
        $existing = self::query()->where('slug', $slug)->first();
        if ($existing !== null) {
            return $existing;
        }

        $owner = User::query()->first() ?? User::create([
            'name' => 'Designer',
            'email' => 'designer@example.test',
            'password' => bcrypt(Str::random(32)),
        ]);

        return self::create(['name' => Str::headline($slug), 'slug' => $slug, 'owner_id' => $owner->id]);
    }
}
