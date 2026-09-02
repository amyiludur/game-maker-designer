<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Games and their versions.
 *
 * `document` is the game-system JSON and is the truth (ADR-0001). `compiled` is a cache —
 * per-card-type schemas, form descriptors, the rules digest — derived deterministically
 * from the document and rebuildable at any time.
 *
 * Published versions are frozen by a database trigger rather than by application discipline.
 * A replay from six months ago has to reproduce against the rules it was played under, and
 * a well-meaning update in a controller would quietly destroy that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('summary')->nullable();
            $table->uuid('current_version_id')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('game_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->string('semver');
            $table->string('status')->default('draft');
            $table->jsonb('document');
            $table->jsonb('compiled')->nullable();
            $table->jsonb('lint')->nullable();
            $table->uuid('parent_version_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'semver']);
            $table->index(['game_id', 'status']);
        });

        Schema::create('game_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_version_id')->constrained()->cascadeOnDelete();
            // The whole game — system plus every card revision at that moment — as one
            // document, so "replay a match from six months ago" is a single read rather
            // than a walk through revision history.
            $table->jsonb('bundle');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gmd_freeze_published_version() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'published' AND NEW.document IS DISTINCT FROM OLD.document THEN
                    RAISE EXCEPTION 'game version % is published and cannot be edited', OLD.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER gmd_freeze_published_version
                BEFORE UPDATE ON game_versions
                FOR EACH ROW EXECUTE FUNCTION gmd_freeze_published_version();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gmd_freeze_published_version ON game_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS gmd_freeze_published_version()');
        Schema::dropIfExists('game_snapshots');
        Schema::dropIfExists('game_versions');
        Schema::dropIfExists('games');
    }
};
