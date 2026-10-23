<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;

/**
 * What the screens get about an invoice. The public page uses document()
 * only, so nothing about the client beyond their name leaves the app.
 */
class InvoiceView
{
    /** @return array<string, mixed> */
    public static function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'uid' => $invoice->uid,
            'number' => $invoice->number,
            'client_id' => $invoice->client_id,
            'client' => $invoice->client->name,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'overdue' => $invoice->isOverdue(),
            'total' => $invoice->total,
            'paid' => $invoice->paid,
            'due' => $invoice->status === 'void' ? 0 : $invoice->due(),
        ];
    }

    /**
     * The invoice as a document: lines, totals, dates.
     *
     * @return array<string, mixed>
     */
    public static function document(Invoice $invoice): array
    {
        return [
            'number' => $invoice->number,
            'client' => $invoice->client->name,
            'company' => $invoice->client->company,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'overdue' => $invoice->isOverdue(),
            'notes' => $invoice->notes,
            'total' => $invoice->total,
            'usd' => $invoice->usd,
            'paid' => $invoice->paid,
            'due' => $invoice->status === 'void' ? 0 : $invoice->due(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'description' => $item->description,
                'period_from' => $item->period_from?->toDateString(),
                'period_to' => $item->period_to?->toDateString(),
                'results' => $item->results,
                'clicks' => $item->clicks,
                'usd' => $item->usd,
                'rate' => $item->rate !== null ? (float) $item->rate : null,
                'amount' => $item->amount,
            ])->values()->all(),
        ];
    }

    /**
     * Payments as the client may see them: no SMS text, no internal notes.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicPayments(Invoice $invoice): array
    {
        return array_values($invoice->payments
            ->filter(fn (InvoicePayment $p) => $p->status === 'verified' || $p->isClaim())
            ->sortBy('id')
            ->map(fn (InvoicePayment $p) => [
                'id' => $p->id,
                'method' => $p->method->label(),
                'amount' => $p->amount,
                'status' => $p->status,
                'reference' => $p->reference,
                'at' => ($p->verified_at ?? $p->created_at)->toIso8601String(),
            ])->all());
    }

    /** @return array<string, mixed> */
    public static function payment(InvoicePayment $p): array
    {
        return [
            'id' => $p->id,
            'method' => $p->method->value,
            'method_label' => $p->method->label(),
            'amount' => $p->amount,
            'status' => $p->status,
            'reference' => $p->reference,
            'sender' => $p->sender,
            'session' => $p->expires_at !== null,
            'expires_at' => $p->expires_at?->toIso8601String(),
            'paid_on' => $p->paid_on?->toDateString(),
            'wallet' => $p->wallet,
            'usd_credit' => $p->usd_credit,
            'bdt_entry_id' => $p->bdt_entry_id,
            'fee_entry_id' => $p->fee_entry_id,
            'note' => $p->note,
            'verified_by' => $p->verifier?->name,
            'auto' => $p->status === 'verified' && $p->verified_by === null,
            'created_at' => $p->created_at->toIso8601String(),
            'verified_at' => $p->verified_at?->toIso8601String(),
        ];
    }
}
