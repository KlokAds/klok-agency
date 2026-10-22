<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ExpenseHead;
use Illuminate\Database\Seeder;

class BooksSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => 'main', 'name' => 'Main Card', 'kind' => 'card'],
            ['code' => 'other', 'name' => 'Other Account', 'kind' => 'holding'],
            ['code' => 'meta', 'name' => 'Meta Ads', 'kind' => 'platform'],
            ['code' => 'google', 'name' => 'Google Ads', 'kind' => 'platform'],
            ['code' => 'tiktok', 'name' => 'TikTok Ads', 'kind' => 'platform'],
        ];

        foreach ($accounts as $sort => $account) {
            Account::updateOrCreate(['code' => $account['code']], $account + ['sort' => $sort]);
        }

        foreach (['Domain & Hosting', 'Transport', 'Software', 'Office', 'Mobile & Internet', 'Bank charge', 'Others'] as $name) {
            ExpenseHead::firstOrCreate(['name' => $name]);
        }
    }
}
