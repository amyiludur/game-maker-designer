<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decks, versioned and pinned.
 *
 * A deck version names the game version it was built against. That pin is what makes an
 * A/B balance test possible — the same decks against v0.4 and v0.5 — and what stops a
 * three-month-old replay from being reinterpreted by today's cards.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('decks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('archetype')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('head_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('deck_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->foreignUuid('game_version_id')->constrained('game_versions')->cascadeOnDelete();
            $table->jsonb('document');
            // Legality is computed once and cached here, so the deck builder's curve chart
            // and its "why is this illegal" panel read the same answer rather than each
            // working it out.
            $table->jsonb('legality')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['deck_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_versions');
        Schema::dropIfExists('decks');
    }
};
