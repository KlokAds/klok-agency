<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentSms;
use App\Models\Wallet;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Services\Invoices;
use App\Support\Access;
use App\Support\ClientMessage;
use App\Support\InvoiceView;
use App\Support\Lookups;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agency side of invoices: list, write, send, take payment, void —
 * and, for the Owner, wallet SMS that matched no invoice by themselves.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_keys(Invoice::STATUSES), 'unpaid', 'overdue'])],
            'client' => ['nullable', 'integer'],
        ]);

        $invoices = Invoice::with('client:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'unpaid' => $q->whereIn('status', Invoice::PAYABLE),
                'overdue' => $q->whereIn('status', Invoice::PAYABLE)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString()),
                default => $q->where('status', $status),
            })
            ->when($filters['client'] ?? null, fn ($q, $client) => $q->where('client_id', $client))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->latest('seq')
            ->paginate(Paging::size($request))
            ->withQueryString()
            ->through(fn (Invoice $i) => InvoiceView::row($i));

        $open = Invoice::whereIn('status', Invoice::PAYABLE);
        $late = Invoice::whereIn('status', Invoice::PAYABLE)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString());
        $owner = $request->user()->hasRole(Access::OWNER);

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters + ['q' => null, 'status' => null, 'client' => null],
            'totals' => [
                'unpaid' => (int) (clone $open)->selectRaw('coalesce(sum(total - paid), 0) as due')->value('due'),
                'overdue' => (int) (clone $late)->selectRaw('coalesce(sum(total - paid), 0) as due')->value('due'),
                'overdue_count' => (clone $late)->count(),
                'paid_month' => (int) InvoicePayment::where('status', 'verified')->where('paid_on', '>=', now()->startOfMonth()->toDateString())->sum('amount'),
                'claims' => InvoicePayment::where('status', 'pending')->whereNull('expires_at')->count(),
            ],
            'sms' => $owner ? PaymentSms::where('status', 'unmatched')->latest('received_at')->limit(20)->get()
                ->map(fn (PaymentSms $s) => [
                    'id' => $s->id, 'provider' => $s->provider, 'amount' => $s->amount, 'trx_id' => $s->trx_id,
                    'payer' => $s->payer_number, 'body' => $s->body, 'at' => $s->received_at->toIso8601String(),
                ]) : null,
            'options' => [
                'clients' => Lookups::clients(),
                'statuses' => Lookups::options(Invoice::STATUSES),
                'open' => $owner ? Invoice::with('client:id,name')->whereIn('status', Invoice::PAYABLE)->latest('seq')->limit(200)->get()
                    ->map(fn (Invoice $i) => ['value' => $i->id, 'label' => $i->number.' · '.$i->client?->name, 'hint' => Money::bdt($i->due())])->all() : [],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request->integer('client') ?: null);
    }

    public function edit(Invoice $invoice): Response|RedirectResponse
    {
        if (! $invoice->isEditable()) {
            $this->toast(__('টাকা আসা বা বাতিল invoice বদলানো যায় না।'), 'warning');

            return redirect()->route('invoices.show', $invoice);
        }

        return $this->form($invoice->load('items'), $invoice->client_id);
    }

    public function store(Request $request, Invoices $invoices): RedirectResponse
    {
        $invoice = $invoices->save($this->validated($request));
        $this->toast(__('Invoice :number তৈরি হয়েছে।', ['number' => $invoice->number]));

        return redirect()->route('invoices.show', $invoice);
    }

    public function update(Request $request, Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        abort_unless($invoice->isEditable(), 422);

        $invoices->save($this->validated($request), $invoice);
        $this->toast(__('Invoice আপডেট হয়েছে।'));

        return redirect()->route('invoices.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['client', 'items', 'payments.verifier:id,name', 'author:id,name']);
        $links = ClientMessage::links($invoice->client->phone, ClientMessage::invoice($invoice));

        return Inertia::render('invoices/Show', [
            'business' => PaySettings::business(),
            'invoice' => InvoiceView::document($invoice) + [
                'id' => $invoice->id,
                'uid' => $invoice->uid,
                'client_id' => $invoice->client_id,
                'url' => $invoice->publicUrl(),
                'editable' => $invoice->isEditable(),
                'payable' => $invoice->isPayable(),
                'allow_partial' => $invoice->allow_partial,
                'created_at' => $invoice->created_at?->toIso8601String(),
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'voided_at' => $invoice->voided_at?->toIso8601String(),
                'author' => $invoice->author?->name,
                'client_phone' => $invoice->client->phone,
                'client_email' => $invoice->client->email,
            ],
            'payments' => $invoice->payments->sortByDesc('id')
                ->reject(fn (InvoicePayment $p) => $p->status === 'expired' && $p->expires_at !== null)
                ->map(fn (InvoicePayment $p) => InvoiceView::payment($p))->values(),
            'share' => [
                'text' => $links['text'],
                'whatsapp' => $links['whatsapp'] ?? 'https://wa.me/?text='.rawurlencode($links['text']),
                'sms' => $links['sms'] ?? 'sms:?body='.rawurlencode($links['text']),
                'mail' => InvoiceMail::configured() && filled($invoice->client->email),
            ],
            'options' => [
                'methods' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label(), 'wallet' => $m->wallet()])->all(),
                'wallets' => Lookups::wallets(),
            ],
        ]);
    }

    /** Pressing any share button means the invoice is now with the client. */
    public function send(Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        abort_if($invoice->status === 'void', 422);

        $invoices->markSent($invoice);

        return back();
    }

    public function email(Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        $invoice->load('client');
        abort_unless(InvoiceMail::configured() && filled($invoice->client->email) && $invoice->status !== 'void', 422);

        Mail::to((string) $invoice->client->email)->send(new InvoiceMail($invoice));
        $invoices->markSent($invoice);
        $this->toast(__('Invoice email-এ পাঠানো হয়েছে।'));

        return back();
    }

    /** Money taken by hand (or a client's claim checked against the statement). */
    public function pay(Request $request, Invoice $invoice, Invoices $invoices, PaymentMatcher $matcher): RedirectResponse
    {
        abort_if($invoice->status === 'void', 422);

        $data = $request->validate([
            'payment_id' => ['nullable', 'integer'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'wallet' => ['required', Rule::in(Wallet::keys())],
            'paid_on' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'amount' => ['required', Money::RULE],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [], ['amount' => __('পরিমাণ'), 'paid_on' => __('তারিখ')]);

        $amount = Money::toMinor($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
        }

        // A TrxID typed in is checked against the SMS the phone sent: never used twice, and
        // not for more than the SMS says came in.
        $reference = filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null;
        if ($reference !== null) {
            if (PaymentMatcher::referenceUsed($reference, isset($data['payment_id']) ? (int) $data['payment_id'] : null)) {
                throw ValidationException::withMessages(['reference' => __('এই TrxID আগেই অন্য একটা payment-এ ব্যবহার হয়েছে।')]);
            }
            $sms = PaymentMatcher::smsFor($reference);
            if ($sms !== null && (int) $sms->amount < $amount) {
                throw ValidationException::withMessages(['amount' => __('এই TrxID-এর SMS-এ এসেছে মাত্র :amount।', ['amount' => Money::bdt((int) $sms->amount)])]);
            }
        }

        $payment = ! empty($data['payment_id'])
            ? $invoice->payments()->where('status', 'pending')->findOrFail((int) $data['payment_id'])
            : $invoice->payments()->create(['method' => $data['method'], 'amount' => $amount, 'status' => 'pending']);

        $payment->forceFill([
            'method' => $data['method'],
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : $payment->reference,
            'expires_at' => null,
        ])->save();

        $invoices->verify($payment, $amount, $request->user(), $data['paid_on'], $data['wallet'], __('হাতে লেখা'));
        $sms = $matcher->linkSms($payment);
        $this->toast($sms !== null
            ? __('পেমেন্ট লেখা হয়েছে — এই TrxID-এর SMS server-এ ছিল, মিলিয়ে রাখা হলো।')
            : ($reference !== null
                ? __('পেমেন্ট লেখা হয়েছে। এই TrxID-এর SMS এখনো আসেনি — এলে নিজে থেকে এর সাথে জুড়বে।')
                : __('পেমেন্ট লেখা হয়েছে — BDT খাতায় client পেমেন্ট হিসেবে গেছে।')));

        return back();
    }

    public function reject(Request $request, Invoice $invoice, InvoicePayment $payment): RedirectResponse
    {
        abort_unless($payment->invoice_id === $invoice->id && $payment->status === 'pending', 404);

        $payment->forceFill(['status' => 'rejected', 'expires_at' => null, 'verified_by' => $request->user()->id, 'verified_at' => now()])->save();
        activity('Invoice')->performedOn($invoice)->causedBy($request->user())->withProperties(['payment_id' => $payment->id, 'reference' => $payment->reference])->log('payment rejected');
        $this->toast(__('দাবিটা বাতিল করা হয়েছে।'));

        return back();
    }

    /**
     * Lock the amount (the client pays everything due in one go) or let the
     * client choose how much to pay now. Changeable after sending: it is how
     * the invoice is paid, not what it says.
     */
    public function partial(Request $request, Invoice $invoice): RedirectResponse
    {
        $on = $request->validate(['allow_partial' => ['required', 'boolean']])['allow_partial'];
        $invoice->forceFill(['allow_partial' => (bool) $on])->save();
        activity('Invoice')->causedBy($request->user())->performedOn($invoice)->log($on ? 'part payments allowed' : 'amount locked');
        $this->toast($on ? __('Client এখন অল্প অল্প করে দিতে পারবেন।') : __('Client-কে পুরো বাকি একবারে দিতে হবে।'));

        return back();
    }

    public function void(Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        if ($invoice->paid > 0) {
            throw ValidationException::withMessages(['invoice' => __('টাকা এসেছে এমন invoice বাতিল করা যায় না — আগে BDT খাতা থেকে পেমেন্টটা মুছুন।')]);
        }

        $invoices->void($invoice);
        $this->toast(__('Invoice বাতিল হয়েছে।'));

        return back();
    }

    /** The Owner says which invoice an unmatched wallet SMS paid. */
    public function assignSms(Request $request, PaymentSms $sms, PaymentMatcher $matcher): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);
        $invoice = Invoice::findOrFail((int) $data['invoice_id']);

        if (! $matcher->assign($sms, $invoice, $request->user())) {
            throw ValidationException::withMessages(['invoice_id' => __('এই SMS আর মেলানো যাচ্ছে না।')]);
        }

        $this->toast(__('SMS-এর টাকা :number-এ জমা হয়েছে।', ['number' => $invoice->number]));

        return back();
    }

    public function ignoreSms(Request $request, PaymentSms $sms): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        if ($sms->status === 'unmatched') {
            $sms->forceFill(['status' => 'ignored'])->save();
        }

        return back();
    }

    /** The client's campaigns with their spend and results over a period, for campaign lines. */
    public function campaigns(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client' => ['required', 'exists:clients,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'campaigns' => Invoices::campaignSpend((int) $data['client'], (string) $data['from'], (string) $data['to']),
            'rate' => Invoices::clientRate(),
        ]);
    }

    private function form(?Invoice $invoice, ?int $clientId): Response
    {
        return Inertia::render('invoices/Form', [
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'uid' => $invoice->uid,
                'number' => $invoice->number,
                'client_id' => $invoice->client_id,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'notes' => $invoice->notes,
                'items' => $invoice->items->map(fn (InvoiceItem $i) => [
                    'kind' => $i->kind,
                    'description' => $i->description,
                    'campaign_id' => $i->campaign_id,
                    'period_from' => $i->period_from?->toDateString(),
                    'period_to' => $i->period_to?->toDateString(),
                    'days' => $i->days,
                    'results' => $i->results,
                    'clicks' => $i->clicks,
                    'usd' => $i->usd !== null ? Money::toInput($i->usd) : '',
                    'rate' => $i->rate !== null ? rtrim(rtrim((string) $i->rate, '0'), '.') : '',
                    'amount' => Money::toInput($i->amount),
                ])->values()->all(),
            ] : null,
            'next_number' => $invoice ? $invoice->number : Invoice::numberFor(Invoice::nextSeq()),
            'client_id' => $clientId,
            'pricing' => [
                'fx_rate' => Invoices::fxRate(),
                'commission' => Invoices::commissionPercent(),
                'rate' => Invoices::clientRate(),
            ],
            'options' => [
                'clients' => Lookups::clients(),
                'kinds' => Lookups::options(InvoiceItem::KINDS),
                // For an advance line: which campaign the days are for.
                'campaigns' => Campaign::whereNotNull('client_id')->orderBy('name')->get()
                    ->map(fn (Campaign $c) => [
                        'value' => $c->id, 'label' => $c->name, 'client_id' => $c->client_id,
                        'budget' => $c->dailyBudget(), 'end' => $c->lastDay()?->toDateString(), 'live' => $c->isLive(),
                        'status' => $c->hasEnded() ? 'ended' : ($c->isLive() ? 'active' : 'paused'),
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * @return array{client_id: int, issue_date: string, due_date: ?string, notes: ?string, items: list<array{kind: string, description: string, usd: ?int, rate: ?float, amount: ?int, campaign_id: ?int, period_from: ?string, period_to: ?string, results: ?int, clicks: ?int}>}
     */
    private function validated(Request $request): array
    {
        $usdKinds = implode(',', InvoiceItem::USD_KINDS);
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.kind' => ['required', Rule::in(array_keys(InvoiceItem::KINDS))],
            'items.*.description' => ['nullable', "required_unless:items.*.kind,{$usdKinds}", 'string', 'max:255'],
            'items.*.usd' => ['nullable', "required_if:items.*.kind,{$usdKinds}", Money::RULE],
            'items.*.rate' => ['nullable', "required_if:items.*.kind,{$usdKinds}", 'numeric', 'min:1', 'max:1000'],
            'items.*.amount' => ['nullable', "required_unless:items.*.kind,{$usdKinds}", Money::RULE],
            'items.*.campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $request->integer('client_id'))],
            'items.*.period_from' => ['nullable', 'date'],
            'items.*.period_to' => ['nullable', 'date'],
            'items.*.results' => ['nullable', 'integer', 'min:0'],
            'items.*.clicks' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'client_id' => 'client', 'issue_date' => __('তারিখ'), 'due_date' => __('শেষ তারিখ'),
            'items.*.description' => __('বিবরণ'), 'items.*.usd' => 'USD', 'items.*.rate' => 'Rate', 'items.*.amount' => __('পরিমাণ'),
        ]);

        $items = [];

        foreach (array_values($data['items']) as $i => $line) {
            $priced = in_array($line['kind'], InvoiceItem::USD_KINDS, true);
            $item = [
                'kind' => (string) $line['kind'],
                'description' => trim((string) ($line['description'] ?? '')),
                'usd' => $priced ? Money::toMinor($line['usd']) : null,
                'rate' => $priced ? (float) $line['rate'] : null,
                'amount' => $priced ? null : Money::toMinor($line['amount']),
                'campaign_id' => isset($line['campaign_id']) ? (int) $line['campaign_id'] : null,
                'period_from' => $line['period_from'] ?? null,
                'period_to' => $line['period_to'] ?? null,
                'results' => isset($line['results']) ? (int) $line['results'] : null,
                'clicks' => isset($line['clicks']) ? (int) $line['clicks'] : null,
            ];

            if (($priced ? $item['usd'] : $item['amount']) <= 0) {
                throw ValidationException::withMessages(["items.{$i}.".($priced ? 'usd' : 'amount') => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
            }

            $items[] = $item;
        }

        return [
            'client_id' => (int) $data['client_id'],
            'issue_date' => (string) $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ];
    }
}
