<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The everyday jobs, each written to the right ledgers in one go — so
 * "bought USD" can never land in one book and not the other.
 * All amounts arrive as positive minor units.
 */
class Postings
{
    /** @param  array<string, mixed>  $data */
    public function adSpend(array $data): UsdEntry
    {
        $client = Client::findOrFail((int) $data['client_id']);
        $account = Account::findOrFail((int) $data['account_id']);

        return UsdEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $data['description'] ?: "{$client->name} - {$account->name} ad spend",
            'category' => 'client_spend',
            'account_id' => $account->id,
            'client_id' => $client->id,
            'amount' => -$data['amount'],
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function ownSpend(array $data): UsdEntry
    {
        return UsdEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $data['description'],
            'category' => 'business_spend',
            'account_id' => $data['account_id'],
            'amount' => -$data['amount'],
            'status' => 'na',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function cardCharge(array $data): UsdEntry
    {
        $account = Account::findOrFail((int) $data['account_id']);

        if ($account->kind !== 'platform') {
            throw ValidationException::withMessages(['account_id' => __('Card charge শুধু ad platform-এর জন্য।')]);
        }

        return UsdEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $data['description'] ?: "{$account->name} card charge",
            'category' => 'card_charge',
            'account_id' => $account->id,
            'amount' => -$data['amount'],
            'status' => 'na',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * BDT received from a client; the chosen pending spend rows become Settled.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $settleIds
     */
    public function clientPayment(array $data, array $settleIds = []): BdtEntry
    {
        return DB::transaction(function () use ($data, $settleIds) {
            $client = Client::findOrFail((int) $data['client_id']);

            $payment = BdtEntry::create([
                'entry_date' => $data['entry_date'],
                'description' => $data['description'] ?: "{$client->name} payment received",
                'category' => $data['category'] ?? 'client_payment',
                'client_id' => $client->id,
                'pay_via' => $data['pay_via'] ?? 'mfs',
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            if ($settleIds !== []) {
                $rows = UsdEntry::whereIn('id', $settleIds)
                    ->where('client_id', $client->id)
                    ->where('category', 'client_spend')
                    ->where('status', 'pending')
                    ->get();

                foreach ($rows as $row) {
                    $row->update(['status' => 'settled', 'settled_by_id' => $payment->id]);
                }
            }

            return $payment;
        });
    }

    /**
     * Buying USD: a new FX batch, USD into the card, BDT out.
     *
     * @param  array<string, mixed>  $data
     */
    public function buyUsd(array $data): FxBatch
    {
        return DB::transaction(function () use ($data) {
            $number = (int) FxBatch::withTrashed()->max('number') + 1;
            $description = $data['description'] ?: "Deposit {$number}";

            $batch = FxBatch::create([
                'number' => $number,
                'purchased_on' => $data['entry_date'],
                'description' => $description,
                'usd' => $data['usd'],
                'bdt' => $data['bdt'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncBatchEntries($batch);

            return $batch;
        });
    }

    /** Keeps the deposit and purchase rows in step with their batch. */
    public function syncBatchEntries(FxBatch $batch): void
    {
        $usd = number_format($batch->usd / 100, 2);

        UsdEntry::updateOrCreate(['fx_batch_id' => $batch->id], [
            'entry_date' => $batch->purchased_on,
            'description' => "{$batch->description} - bought USD",
            'category' => 'deposit',
            'account_id' => Account::card()->id,
            'amount' => $batch->usd,
            'status' => 'na',
            'notes' => "Batch {$batch->number}",
        ]);

        BdtEntry::updateOrCreate(['fx_batch_id' => $batch->id], [
            'entry_date' => $batch->purchased_on,
            'description' => "{$batch->description} - bought {$usd} USD",
            'category' => 'usd_purchase',
            'amount' => -$batch->bdt,
            'notes' => "Batch {$batch->number}",
        ]);
    }

    public function deleteBatch(FxBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            UsdEntry::where('fx_batch_id', $batch->id)->get()->each->delete();
            BdtEntry::where('fx_batch_id', $batch->id)->get()->each->delete();
            $batch->delete();
        });
    }

    /**
     * USD moved between two of our own accounts (e.g. card ↔ holding).
     *
     * @param  array<string, mixed>  $data
     */
    public function transfer(array $data): string
    {
        if ((int) $data['from_id'] === (int) $data['to_id']) {
            throw ValidationException::withMessages(['to_id' => __('একই account-এ transfer হয় না।')]);
        }

        return DB::transaction(function () use ($data) {
            $key = (string) Str::uuid();
            $from = Account::findOrFail((int) $data['from_id']);
            $to = Account::findOrFail((int) $data['to_id']);
            $description = $data['description'] ?: "{$from->name} → {$to->name}";

            foreach ([[$from, -$data['amount']], [$to, $data['amount']]] as [$account, $amount]) {
                UsdEntry::create([
                    'entry_date' => $data['entry_date'],
                    'description' => $description,
                    'category' => 'transfer',
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'status' => 'na',
                    'pair_key' => $key,
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            return $key;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function expense(array $data): BdtEntry
    {
        return BdtEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $data['description'],
            'category' => 'expense',
            'expense_head_id' => $data['expense_head_id'] ?? null,
            'amount' => -$data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Owner putting money in (capital) or taking it out (drawing).
     *
     * @param  array<string, mixed>  $data
     */
    public function owner(array $data): BdtEntry
    {
        $in = $data['direction'] === 'in';

        return BdtEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $data['description'] ?: ($in ? 'Capital - own money in' : 'Owner drawing'),
            'category' => $in ? 'capital' : 'owner_drawing',
            'amount' => $in ? $data['amount'] : -$data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function salary(array $data): BdtEntry
    {
        $employee = Employee::findOrFail((int) $data['employee_id']);
        $kind = $data['kind'] ?? 'salary';

        return BdtEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $employee->name.' — '.BdtEntry::SALARY_KINDS[$kind].' '.$data['salary_month'],
            'category' => 'salary',
            'employee_id' => $employee->id,
            'salary_month' => $data['salary_month'],
            'salary_kind' => $kind,
            'amount' => -$data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
