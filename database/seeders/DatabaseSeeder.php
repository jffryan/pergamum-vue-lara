<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Formats are config, not user data — every other domain object is
        // created through the app, but a fresh database has to start with these.
        $this->call(FormatSeeder::class);
    }
}
