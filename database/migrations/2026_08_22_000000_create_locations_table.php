<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One self-referencing table rather than rooms + bookcases + shelves:
     * the depth is a convention, not a schema. A box in the attic or a
     * "lent to Dave" pile lands as a row with a different `kind`, not as a
     * new table. See /feature-plans/locations.md.
     *
     * `code` is the stable machine identity ('O1S5', 'O1', 'O') — it is what
     * the CSV importer matches and what the slug derives from, so a location
     * can be renamed (`name`) without moving its identity. `parent_id` is
     * `restrict`, not `cascade`: deleting a room must never vaporize its
     * bookcases and orphan every shelved copy in one statement. The
     * "move or empty the children first" rule lives in LocationService.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id('location_id');
            $table->unsignedBigInteger('parent_id')->nullable()->default(null)->index();
            $table->foreign('parent_id')->references('location_id')
                ->on('locations')
                ->onDelete('restrict');
            $table->string('code');
            $table->string('name')->nullable()->default(null);
            $table->string('kind');
            $table->string('slug')->unique();
            $table->unsignedInteger('ordinal')->nullable()->default(null);
            $table->timestamps();

            // Two roots can't share a code either, despite NULL parents being
            // distinct to this index — the unique slug (lowercased code)
            // closes that hole.
            $table->unique(['parent_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
