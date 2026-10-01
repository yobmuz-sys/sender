<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately empty. A seeder that creates a known-password account is a
     * production backdoor waiting to happen, and the platform has no sample
     * data to install. The first administrator is created by registering and
     * then running: php artisan sender:set-role <email> super_admin
     */
    public function run(): void
    {
        //
    }
}
