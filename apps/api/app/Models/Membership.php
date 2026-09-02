<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Who may do what in a workspace. */
final class Membership extends Model
{
    use HasUuidV7;

    public const OWNER = 'owner';
    public const DESIGNER = 'designer';
    public const PLAYTESTER = 'playtester';
    public const VIEWER = 'viewer';

    protected $fillable = ['workspace_id', 'user_id', 'role'];

    /** A playtester can join matches and file notes; only designers change the game. */
    public function mayAuthor(): bool
    {
        return in_array($this->role, [self::OWNER, self::DESIGNER], true);
    }
}
