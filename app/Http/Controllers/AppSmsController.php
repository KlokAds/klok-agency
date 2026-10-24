<?php

namespace App\Http\Controllers;

use App\Models\PaymentSms;
use App\Models\Setting;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone app's SMS Center (Owner only): every money SMS the server kept, with the invoice it
 * paid; a week of what came in, day by day; and the senders the phone forwards — which the
 * Owner can add to and take from on the phone, the same list as Settings › Payment.
 */
class AppSmsController extends Controller
{
    /**
     * Banks and wallets in Bangladesh as their SMS name usually shows, to pick from on the
     * phone. The phone's own name for a sender is what counts, so one can always be typed.
     */
    public const CATALOG = [
        'mfs' => ['bKash', 'NAGAD', '16216', 'Rocket', 'upay', 'Tap', 'OK Wallet', 'CellFin', 'DGePAY'],
        'bank' => [
            'AB BANK', 'AIBL', 'Agrani Bank', 'Al-Arafah', 'BANK ASIA', 'BASIC BANK', 'Bengal Bank', 'BRAC BANK', 'CITY BANK',
            'Community Bank', 'DBBL', 'DHAKA BANK', 'EBL', 'EXIM BANK', 'FSIBL', 'Global Islami', 'HSBC', 'IBBL', 'IslamiBank',
            'Jamuna Bank', 'Janata Bank', 'Meghna Bank', 'Mercantile', 'Midland Bank', 'MTB', 'NCC BANK', 'NRB Bank', 'ONE BANK',
            'Padma Bank', 'Premier Bank', 'PRIME BANK', 'Pubali Bank', 'Rupali Bank', 'SBAC', 'SCB', 'SEBL', 'Shahjalal',
            'SIBL', 'Sonali Bank', 'Standard Bank', 'Trust Bank', 'UCB', 'UCBL', 'Union Bank', 'Uttara Bank',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $history = PaymentSms::with('payment.invoice:id,uid,number,client_id', 'payment.invoice.client:id,name')
            ->latest('received_at')->limit(300)->get()
            ->map(fn (PaymentSms $s) => [
                'id' => $s->id,
                'provider' => $s->provider,
                'sender' => $s->sender,
                'body' => $s->body,
                'amount' => $s->amount,
                'payer' => $s->payer_number,
                'trx_id' => $s->trx_id,
                'status' => $s->status,
                'at' => $s->received_at->getTimestampMs(),
                'invoice' => $s->payment?->invoice?->number,
                'invoice_url' => $s->payment?->invoice ? '/invoices/'.$s->payment->invoice->uid : null,
                'client' => $s->payment?->invoice?->client?->name,
            ]);

        // A week of money in, day by day (refunds left out).
        $from = CarbonImmutable::now()->startOfDay()->subDays(6);
        $days = PaymentSms::where('received_at', '>=', $from)->where('status', '!=', 'refund')->get()
            ->groupBy(fn (PaymentSms $s) => $s->received_at->toDateString());
        $week = [];
        for ($day = $from; $day->lte(now()); $day = $day->addDay()) {
            $rows = $days->get($day->toDateString(), collect());
            $week[] = [
                'day' => $day->toDateString(),
                'amount' => (int) $rows->sum('amount'),
                'matched' => (int) $rows->where('status', 'matched')->sum('amount'),
                'count' => $rows->count(),
            ];
        }

        return response()->json([
            'history' => $history,
            'week' => $week,
            'senders' => $this->senders(),
            'catalog' => self::CATALOG,
            'last_seen' => Setting::get('sms_last_seen'),
        ]);
    }

    /** A bank (or other) sender added on the phone: the server reads its SMS from now on too. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:40', 'regex:/^[\pL\pN +\-_.]+$/u']]);

        $name = PaySettings::sender($data['name']);
        if (! in_array($name, $this->builtIn(), true)) {
            $list = PaySettings::bankSenders();
            $list[] = $name;
            Setting::put('pay_bank_sms_senders', implode(', ', array_unique($list)));
            activity('Setting')->causedBy($request->user())->log("sms sender {$name} added on the phone");
        }

        return response()->json(['senders' => $this->senders(), 'forms' => PaySettings::senderForms()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);

        $name = PaySettings::sender($data['name']);
        $list = array_values(array_filter(PaySettings::bankSenders(), fn (string $s) => $s !== $name));
        Setting::put('pay_bank_sms_senders', $list === [] ? null : implode(', ', $list));
        activity('Setting')->causedBy($request->user())->log("sms sender {$name} removed on the phone");

        return response()->json(['senders' => $this->senders(), 'forms' => PaySettings::senderForms()]);
    }

    /** @return list<string> */
    private function builtIn(): array
    {
        return array_merge(...array_values(SmsParser::SENDERS));
    }

    /** @return list<array{name: string, kind: string, fixed: bool}> */
    private function senders(): array
    {
        $out = [];
        foreach (SmsParser::SENDERS as $wallet => $names) {
            foreach ($names as $name) {
                $out[] = ['name' => $name, 'kind' => $wallet, 'fixed' => true];
            }
        }
        foreach (PaySettings::bankSenders() as $name) {
            $out[] = ['name' => $name, 'kind' => 'bank', 'fixed' => false];
        }

        return $out;
    }
}
