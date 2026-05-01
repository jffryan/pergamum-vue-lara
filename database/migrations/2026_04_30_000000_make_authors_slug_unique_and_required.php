<?php

use App\Support\Slugger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $taken = [];

        DB::table('authors')->orderBy('author_id')->each(function ($author) use (&$taken) {
            $base = Slugger::for(trim(($author->first_name ?? '').' '.$author->last_name));

            if ($base === '') {
                $base = 'author-'.$author->author_id;
            }

            $candidate = $base;
            $suffix = 2;
            while (isset($taken[$candidate])) {
                $candidate = $base.'-'.$suffix++;
            }
            $taken[$candidate] = true;

            if ($author->slug !== $candidate) {
                DB::table('authors')
                    ->where('author_id', $author->author_id)
                    ->update(['slug' => $candidate]);
            }
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down()
    {
        Schema::table('authors', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->string('slug')->nullable()->change();
        });
    }
};
