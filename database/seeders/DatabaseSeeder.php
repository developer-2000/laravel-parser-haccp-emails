<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            QuerySeeder::class,
            GroupSeeder::class,
            ExcludeSeeder::class,
            QuerySetSeeder::class,
        ]);
    }
}
