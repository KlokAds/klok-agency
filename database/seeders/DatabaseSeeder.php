<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Access;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AccessSeeder::class, BooksSeeder::class]);

        $email = env('OWNER_EMAIL');
        $password = env('OWNER_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('OWNER_EMAIL / OWNER_PASSWORD not set in .env — no owner account created.');

            return;
        }

        $owner = User::firstOrCreate(['email' => $email], [
            'name' => env('OWNER_NAME', 'Klok Ads'),
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $owner->assignRole(Access::OWNER);
    }
}
