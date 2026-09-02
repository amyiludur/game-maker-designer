<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
