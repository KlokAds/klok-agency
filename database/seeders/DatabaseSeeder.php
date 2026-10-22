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

        $email = config('app.owner.email');
        $password = config('app.owner.password');

        if (! $email || ! $password) {
            $this->command?->warn('OWNER_EMAIL / OWNER_PASSWORD not set in .env — no owner account created.');

            return;
        }

        $owner = User::firstOrCreate(['email' => $email], [
            'name' => config('app.owner.name'),
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $owner->assignRole(Access::OWNER);
    }
}
