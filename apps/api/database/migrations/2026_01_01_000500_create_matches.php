<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matches.
 *
 * `match_actions` is the match. The state in Redis is a cache; the snapshots are an
 * optimisation; the action log plus the initial state is the durable record, and everything
 * else can be rebuilt from it. That is what makes undo exact, recovery transparent, and a
 * replay from a year ago still playable.
 *
 * Append-only is enforced by the database, not by convention: a rule the application layer
 * merely intends to follow is a rule that gets broken by a migration script at 2am.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('game_version_id')->constrained('game_versions')->cascadeOnDelete();
            $table->string('mode')->default('solo');
            $table->string('status')->default('lobby');
            $table->bigInteger('seed');
            $table->jsonb('config')->default('{}');
            $table->jsonb('initial_state');
            $table->jsonb('result')->nullable();
            $table->integer('action_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['game_id', 'status']);
        });

        Schema::create('match_players', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->integer('seat');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('bot_profile_id')->nullable();
            $table->foreignUuid('deck_version_id')->nullable()->constrained('deck_versions')->nullOnDelete();
            $table->string('label')->nullable();

            $table->unique(['match_id', 'seat']);
        });

        Schema::create('match_actions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->integer('sequence');
            $table->integer('seat')->nullable();
            $table->jsonb('action');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['match_id', 'sequence']);
        });

        Schema::create('match_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->integer('sequence');
            $table->jsonb('state');
            $table->string('state_hash');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['match_id', 'sequence']);
        });

        Schema::create('bot_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('strategy')->default('random');
            $table->jsonb('config')->default('{}');
            $table->timestamps();
        });

        // Doc 03 asks for no UPDATE or DELETE grant on these tables for the application
        // role. Without a second database role, a trigger is the closest equivalent — and it
        // has to be careful: a row may still legitimately disappear when its parent is
        // deleted and the foreign key cascades. So an update is always refused, and a delete
        // is refused only while the parent is still there, which is exactly the case where
        // somebody is rewriting history rather than cleaning up after it.
        foreach ([['match_actions', 'matches', 'match_id'], ['card_revisions', 'cards', 'card_id']] as [$table, $parent, $key]) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION gmd_append_only_{$table}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION '{$table} is append-only: rows cannot be changed once written';
                    END IF;

                    IF EXISTS (SELECT 1 FROM {$parent} WHERE id = OLD.{$key}) THEN
                        RAISE EXCEPTION '{$table} is append-only: rows cannot be deleted while their {$parent} row exists';
                    END IF;

                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER gmd_append_only_{$table}
                    BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION gmd_append_only_{$table}();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['match_actions', 'card_revisions'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS gmd_append_only_{$table} ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS gmd_append_only_{$table}()");
        }
        Schema::dropIfExists('bot_profiles');
        Schema::dropIfExists('match_snapshots');
        Schema::dropIfExists('match_actions');
        Schema::dropIfExists('match_players');
        Schema::dropIfExists('matches');
    }
};
