<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cards.
 *
 * The jsonb document is the card; every other column on this table is an index, written
 * only by the card Projector inside the same transaction, and rebuildable by
 * `php artisan cards:reproject` (ADR-0001). Nothing reads an index column to make a game
 * decision — if they were all wrong, matches would still be correct.
 *
 * A deliberate deviation from doc 03, which describes some of these as Postgres generated
 * columns: they are all projector-written instead. A generated column cannot be rebuilt by
 * the reproject command, so the test that proves index columns are droppable would silently
 * cover less than it claims. One mechanism, one test.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->integer('release_order')->default(1);
            $table->string('status')->default('draft');
            $table->jsonb('document');
            $table->timestamps();

            $table->unique(['game_id', 'code']);
        });

        Schema::create('cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->uuid('head_revision_id')->nullable();
            $table->jsonb('document');
            $table->string('status')->default('draft');

            // --- index columns, written only by the projector ---
            $table->string('name')->nullable();
            $table->string('card_type')->nullable();
            $table->string('faction')->nullable();
            $table->integer('cost')->nullable();
            $table->jsonb('traits')->default('[]');
            $table->jsonb('keywords')->default('[]');
            $table->text('search')->nullable();

            $table->timestamps();

            $table->unique(['game_id', 'code']);
            $table->index(['game_id', 'card_type']);
            $table->index(['game_id', 'cost']);
        });

        Schema::create('card_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('card_id')->constrained()->cascadeOnDelete();
            $table->integer('revision');
            $table->jsonb('document');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['card_id', 'revision']);
        });

        // GIN on the document supports the ad-hoc structural questions the card browser
        // asks — "every card with an ability that deals damage" — without a column per
        // question.
        DB::statement('CREATE INDEX cards_document_gin ON cards USING gin (document jsonb_path_ops)');
        DB::statement('CREATE INDEX cards_traits_gin ON cards USING gin (traits jsonb_path_ops)');
        DB::statement('CREATE INDEX cards_keywords_gin ON cards USING gin (keywords jsonb_path_ops)');
        DB::statement("CREATE INDEX cards_search_idx ON cards USING gin (to_tsvector('english', coalesce(search, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('card_revisions');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('sets');
    }
};
