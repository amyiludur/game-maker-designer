<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a cooperative table plays against.
 *
 * A duel is fully described by its decks; a scenario is the other half of a co-op match's
 * inputs, and is pinned into the match beside them so a replay reproduces (doc 16 §3). Same
 * pattern as everything else here: the jsonb document is the truth and every other column
 * is an index nothing makes a game decision from.
 *
 * Encounter sets are their own table rather than rows inside a scenario because they are
 * shared — a modular set is written once and dropped into several scenarios, which is the
 * whole reason the format has them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('encounter_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('kind')->default('scenario');
            $table->jsonb('document');
            $table->timestamps();

            $table->unique(['game_id', 'code']);
        });

        Schema::create('scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');

            // --- index columns, written from the document ---
            $table->string('adversary')->nullable();
            $table->string('difficulty')->nullable();
            $table->integer('min_players')->nullable();
            $table->integer('max_players')->nullable();

            $table->jsonb('document');
            $table->timestamps();

            $table->unique(['game_id', 'code']);
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->foreignUuid('scenario_id')->nullable()->after('game_version_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scenario_id');
        });
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('encounter_sets');
    }
};
