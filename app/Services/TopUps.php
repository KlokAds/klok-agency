<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Notifications\AgencyAlert;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A client adds ad credit on their own, without a campaign or a count of days: they say how much
 * taka, an invoice is made at their dollar rate and sent, and paying it adds the dollars to their
 * credit like any other payment. A newer top-up replaces an unpaid one.
 */
class TopUps
{
    /** The least and most a client may add at once, in poisha. */
    public const MIN_BDT = 50000;

    public const MAX_BDT = 50000000;

    public const NOTE = 'Top-up';

    public const TITLE = 'Client নিজে credit যোগ করতে চান'; // i18n

    public function __construct(private Invoices $invoices, private Notifier $notifier) {}

    /**
     * @param  int  $bdt  poisha
     *
     * @throws RuntimeException when there is no rate to price it at, or the amount is out of range
     */
    public function invoice(Client $client, int $bdt): Invoice
    {
        if ($bdt < self::MIN_BDT || $bdt > self::MAX_BDT) {
            throw new RuntimeException(__('৳:min থেকে ৳:max-এর মধ্যে দিন।', ['min' => number_format(self::MIN_BDT / 100), 'max' => number_format(self::MAX_BDT / 100)]));
        }
        $rate = Invoices::clientRate() ?? throw new RuntimeException(__('এখন কোনো dollar rate নেই — Klok Ads-এর সাথে যোগাযোগ করুন।'));
        // Whole cents of credit for the taka asked; the invoice total is those cents at the rate.
        $usd = (int) floor($bdt / $rate);

        return DB::transaction(function () use ($client, $usd, $rate) {
            foreach (Invoice::where('client_id', $client->id)->where('notes', self::NOTE)->where('status', '!=', 'void')->where('paid', 0)->get() as $old) {
                $this->invoices->void($old);
            }

            $invoice = $this->invoices->save([
                'client_id' => $client->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'notes' => self::NOTE,
                'items' => [[
                    'kind' => 'ad_budget',
                    'description' => __('Ad credit — $:usd', ['usd' => number_format($usd / 100, 2)]),
                    'usd' => $usd,
                    'rate' => $rate,
                    'amount' => null,
                    'campaign_id' => null,
                    'period_from' => null,
                    'period_to' => null,
                    'results' => null,
                    'clicks' => null,
                ]],
            ]);
            $this->invoices->markSent($invoice);

            $this->notifier->send($client->manager_id, new AgencyAlert('topup_invoiced', self::TITLE, '{client} — {number} · {amount} (≈ {usd})', [
                'client' => $client->name, 'number' => $invoice->number, 'amount' => '৳'.number_format($invoice->total / 100), 'usd' => '$'.number_format($usd / 100, 2),
            ], ['url' => '/invoices/'.$invoice->id]));

            return $invoice;
        });
    }
}
