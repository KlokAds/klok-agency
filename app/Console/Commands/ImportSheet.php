<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\ExpenseHead;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use App\Services\Books;
use App\Services\PaymentFees;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('books:import-sheet {file=database/import/sheet-2026-10-22.json} {--fresh : Remove existing ledger rows first}')]
#[Description('Import the old Google Sheet snapshot and check the totals against the sheet')]
class ImportSheet extends Command
{
    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_string($file)) {
            $this->error('Give the path of one snapshot file.');

            return self::FAILURE;
        }

        $path = base_path($file);

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $data = $this->readJson($path);

        if (UsdEntry::withTrashed()->exists() || BdtEntry::withTrashed()->exists()) {
            if (! $this->option('fresh')) {
                $this->error('Ledgers already have rows. Run with --fresh to replace them.');

                return self::FAILURE;
            }
        }

        // Fees come after the totals are checked against the sheet (which had none).
        PaymentFees::$paused = true;

        DB::transaction(function () use ($data) {
            if ($this->option('fresh')) {
                UsdEntry::withTrashed()->forceDelete();
                BdtEntry::withTrashed()->forceDelete();
                FxBatch::withTrashed()->forceDelete();
            }

            $clients = [];
            foreach ($data['clients'] as $row) {
                $clients[$row['name']] = Client::withTrashed()->updateOrCreate(['name' => $row['name']], $row)->id;
            }

            $batches = [];
            foreach ($data['fx_batches'] as $row) {
                $batches[$row['number']] = FxBatch::create([
                    'number' => $row['number'],
                    'description' => $row['description'],
                    'usd' => $this->cents($row['usd']),
                    'bdt' => $this->cents($row['bdt']),
                ])->id;
            }

            $accounts = Account::pluck('id', 'code');
            $pairs = [];
            $sequence = 0;

            foreach ($data['usd'] as $row) {
                $pairKey = null;
                if (isset($row['pair'])) {
                    $pairKey = $pairs[$row['pair']] ??= (string) Str::uuid();
                }

                UsdEntry::create([
                    'entry_date' => $row['date'],
                    'sequence' => ++$sequence,
                    'description' => $row['description'],
                    'category' => $row['category'],
                    'account_id' => $accounts[$row['account']],
                    'client_id' => $row['client'] ? $clients[$row['client']] : null,
                    'amount' => $this->cents($row['amount']),
                    'status' => $row['status'],
                    'fx_batch_id' => isset($row['batch']) ? $batches[$row['batch']] : null,
                    'pair_key' => $pairKey,
                    'notes' => $row['notes'],
                    'review_note' => $row['review'] ?? null,
                ]);
            }

            $heads = ExpenseHead::pluck('id', 'name');
            $sequence = 0;

            foreach ($data['bdt'] as $row) {
                BdtEntry::create([
                    'entry_date' => $row['date'],
                    'sequence' => ++$sequence,
                    'description' => $row['description'],
                    'category' => $row['category'],
                    'client_id' => $row['client'] ? $clients[$row['client']] : null,
                    'pay_via' => $this->payVia($row['category'], $row['pay_via'] ?? null),
                    'expense_head_id' => isset($row['head']) ? $heads[$row['head']] : null,
                    'fx_batch_id' => isset($row['batch']) ? $batches[$row['batch']] : null,
                    'amount' => $this->cents($row['amount']),
                    'notes' => $row['notes'],
                    'review_note' => $row['review'] ?? null,
                ]);
            }
        });

        $this->info(sprintf('Imported %d clients, %d batches, %d USD rows, %d BDT rows.',
            count($data['clients']), count($data['fx_batches']), count($data['usd']), count($data['bdt'])));

        if (! $this->verify($data['expected'])) {
            return self::FAILURE;
        }

        // The sheet is checked; now what happened after it, and the MFS payment fees.
        $this->applyAdditions();
        PaymentFees::$paused = false;
        $this->info('Payment fees added to '.PaymentFees::backfill().' client payments ('.PaymentFees::label().').');

        return self::SUCCESS;
    }

    /** The sheet does not say how money came in; client payments count as MFS unless a row says so. */
    private function payVia(string $category, ?string $given): ?string
    {
        if (! in_array($category, ['client_payment', 'service_income'], true)) {
            return null;
        }

        return in_array($given, array_keys(BdtEntry::PAY_VIA), true) ? $given : 'mfs';
    }

    /** Entries made after the sheet snapshot, kept in database/import/after-sheet.json. */
    private function applyAdditions(): void
    {
        $path = base_path('database/import/after-sheet.json');

        if (! is_file($path)) {
            return;
        }

        $rows = $this->readJson($path);
        $accounts = Account::pluck('id', 'code');
        $clients = Client::pluck('id', 'name');

        DB::transaction(function () use ($rows, $accounts, $clients) {
            foreach ($rows as $row) {
                $payment = BdtEntry::create([
                    'entry_date' => $row['date'],
                    'description' => $row['payment_description'],
                    'category' => 'client_payment',
                    'client_id' => $clients[$row['client']],
                    'pay_via' => $this->payVia('client_payment', $row['pay_via'] ?? null),
                    'amount' => $this->cents($row['bdt']),
                    'notes' => $row['notes'] ?? null,
                ]);

                UsdEntry::create([
                    'entry_date' => $row['date'],
                    'description' => $row['spend_description'],
                    'category' => 'client_spend',
                    'account_id' => $accounts[$row['account']],
                    'client_id' => $clients[$row['client']],
                    'amount' => -$this->cents($row['usd']),
                    'status' => 'settled',
                    'settled_by_id' => $payment->id,
                    'notes' => $row['notes'] ?? null,
                ]);
            }
        });

        $this->info('Added '.count($rows).' entries made after the sheet.');
    }

    /** @param  array<string, mixed>  $expected */
    private function verify(array $expected): bool
    {
        $books = app(Books::class);
        $summary = $books->summary();

        $checks = [
            ['Card balance (USD)', $expected['card_balance_usd'], $summary['card']],
            ['Business USD', $expected['business_usd'], $summary['business_usd']],
            ['Platform due (USD)', $expected['platform_due_usd'], $summary['platform_due_book']],
            ['Pending from clients (USD)', $expected['pending_usd'], $summary['receivable_usd']],
            ['BDT balance', $expected['bdt_balance'], $summary['bdt']],
            ['Client margin (BDT)', $expected['client_margin_bdt'], $summary['margin']],
        ];

        $ok = true;
        $rows = [];

        foreach ($checks as [$label, $sheet, $system]) {
            $sheetMinor = $this->cents($sheet);
            // The sheet shows BDT rounded to whole taka.
            $match = abs($sheetMinor - $system) < 100;
            $ok = $ok && $match;
            $rows[] = [$label, $sheet, number_format($system / 100, 2), $match ? 'OK' : 'MISMATCH'];
        }

        $this->table(['Check', 'Sheet', 'System', ''], $rows);
        $this->line(sprintf('Net result: %s BDT (sheet showed -20,500 with the drawing counted as expense)', number_format($summary['net'] / 100, 2)));
        $this->line(sprintf('Assets %s = capital − drawings + net %s → %s',
            number_format($summary['assets'] / 100, 2), number_format($summary['equity'] / 100, 2), $summary['equity_ok'] ? 'OK' : 'MISMATCH'));

        return $ok;
    }

    private function readJson(string $path): mixed
    {
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function cents(string $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
