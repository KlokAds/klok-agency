<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\UsdEntry;
use App\Services\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('reports/Index', [
            'pl' => $books->profitAndLoss($from, $to),
            'monthly' => $books->monthly(12),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'preset' => $request->input('preset', 'all'),
            ],
        ]);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function period(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return match ($request->input('preset', 'all')) {
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfYear()],
            'custom' => [
                $request->date('from') ? CarbonImmutable::parse($request->input('from'))->startOfDay() : null,
                $request->date('to') ? CarbonImmutable::parse($request->input('to'))->endOfDay() : null,
            ],
            default => [null, null],
        };
    }

    public function export(Request $request, string $book, Books $books): StreamedResponse
    {
        abort_unless(in_array($book, ['usd', 'bdt', 'pl'], true), 404);
        abort_unless($request->user()->can("{$book}.view") || $book === 'pl', 403);

        $name = 'klok-finance-'.$book.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($book, $books, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads Bangla correctly

            match ($book) {
                'usd' => $this->usdCsv($out, $books),
                'bdt' => $this->bdtCsv($out, $books),
                'pl' => $this->plCsv($out, $books, ...$this->period($request)),
            };

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function usdCsv($out, Books $books): void
    {
        $fifo = $books->fifo()['entries'];
        $card = $books->cardRunning();

        fputcsv($out, ['Date', 'Description', 'Category', 'Account', 'Client', 'Amount (USD)', 'Status', 'Card balance', 'FIFO cost (BDT)', 'Notes']);

        foreach ($books->usdEntries() as $e) {
            fputcsv($out, [
                $e->entry_date?->toDateString(), $e->description, UsdEntry::categoryLabel($e->category),
                $e->account?->name, $e->client?->name, $this->num($e->amount), UsdEntry::STATUSES[$e->status] ?? $e->status,
                isset($card[$e->id]) ? $this->num($card[$e->id]) : '', isset($fifo[$e->id]) ? $this->num($fifo[$e->id]['cost']) : '',
                $e->notes,
            ]);
        }
    }

    private function bdtCsv($out, Books $books): void
    {
        $running = $books->bdtRunning();

        fputcsv($out, ['Date', 'Description', 'Category', 'Client', 'Head', 'Amount (BDT)', 'Balance', 'Notes']);

        foreach ($books->bdtEntries() as $e) {
            fputcsv($out, [
                $e->entry_date?->toDateString(), $e->description, BdtEntry::categoryLabel($e->category),
                $e->client?->name ?? $e->employee?->name, $e->head?->name, $this->num($e->amount),
                $this->num($running[$e->id]), $e->notes,
            ]);
        }
    }

    private function plCsv($out, Books $books, $from, $to): void
    {
        $pl = $books->profitAndLoss($from, $to);

        fputcsv($out, ['Profit & Loss', $from ? $from->toDateString().' — '.$to?->toDateString() : 'All time']);
        fputcsv($out, []);
        fputcsv($out, ['Client', 'USD spent (settled)', 'FIFO cost (BDT)', 'BDT received', 'Margin (BDT)', 'Pending USD']);

        foreach ($pl['clients'] as $c) {
            fputcsv($out, [$c['name'], $this->num($c['usd_settled']), $this->num($c['cost_settled']), $this->num($c['received']), $this->num($c['margin']), $this->num($c['usd_pending'])]);
        }

        fputcsv($out, ['Total margin', '', '', '', $this->num($pl['margin'])]);
        fputcsv($out, []);
        fputcsv($out, ['Expenses']);
        fputcsv($out, ['Own USD spend (FIFO cost)', $this->num($pl['own_cost'])]);

        foreach ($pl['expenses'] as $x) {
            fputcsv($out, [$x['name'], $this->num($x['amount'])]);
        }

        fputcsv($out, ['Total expenses', $this->num($pl['total_expense'])]);
        fputcsv($out, []);
        fputcsv($out, ['Net profit / (loss)', $this->num($pl['net'])]);
    }

    private function num(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
