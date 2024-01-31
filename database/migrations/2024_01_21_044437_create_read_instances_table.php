<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Book;
use App\Models\ReadInstance;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('read_instances', function (Blueprint $table) {
            $table->id("read_instances_id");
            $table->foreignId("book_id")->constrained("books", "book_id")->onDelete('cascade');;
            $table->foreignId("version_id")->nullable()->constrained("versions", "version_id");
            $table->date("date_read");
            $table->timestamps();
        });

        // Migrate existing data
        $books = Book::where('is_completed', true)->get();
        foreach ($books as $book) {
            if ($book->date_completed) {
                $formattedDate = Carbon::createFromFormat('m/d/Y', $book->date_completed)->format('Y-m-d');
                ReadInstance::create([
                    'book_id' => $book->book_id,
                    'date_read' => $formattedDate
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('read_instances');
    }
};
