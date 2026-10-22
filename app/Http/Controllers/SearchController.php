<?php

namespace App\Http\Controllers;

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Task;
use App\Models\UsdEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The search box at the top: clients, campaigns, BDT and USD rows and
 * tasks matching what was typed, each only for people allowed to see it.
 */
class SearchController extends Controller
{
    private const PER_GROUP = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:80']])['q']);
        $user = $request->user();

        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $groups = [];

        if ($user->can('clients.view')) {
            $groups[] = ['key' => 'clients', 'label' => 'Client', 'items' => Client::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Client $c) => ['title' => $c->name, 'hint' => trim(($c->company ?? '').' '.($c->phone ?? '')) ?: null, 'url' => '/clients/'.$c->id])->all()];
        }

        if ($user->can('runs.view')) {
            $groups[] = ['key' => 'campaigns', 'label' => 'Campaign', 'items' => Campaign::with(['client:id,name', 'account:id,name'])->where('name', 'like', $like)
                ->orderByDesc('last_spend_day')->limit(self::PER_GROUP)->get()
                ->map(fn (Campaign $c) => ['title' => $c->name, 'hint' => trim(($c->client->name ?? __('Client বাছা হয়নি')).' · '.($c->account->name ?? '')), 'url' => '/campaigns?account='.$c->pilot_account_id.'&q='.urlencode($c->name)])->all()];
        }

        if ($user->can('bdt.view')) {
            $groups[] = ['key' => 'bdt', 'label' => __('BDT খাতা'), 'items' => BdtEntry::with('client:id,name')->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('notes', 'like', $like))
                ->orderByDesc('entry_date')->limit(self::PER_GROUP)->get()
                ->map(fn (BdtEntry $e) => [
                    'title' => $e->description,
                    'hint' => trim(($e->entry_date?->format('j M Y') ?? '').' · '.BdtEntry::categoryLabel($e->category).' · '."\u{09F3}".number_format(abs($e->amount) / 100, 2)),
                    'url' => '/bdt?q='.urlencode($e->description),
                ])->all()];
        }

        if ($user->can('usd.view')) {
            $groups[] = ['key' => 'usd', 'label' => __('USD খাতা'), 'items' => UsdEntry::where(fn ($w) => $w->where('description', 'like', $like)->orWhere('notes', 'like', $like))
                ->orderByDesc('entry_date')->limit(self::PER_GROUP)->get()
                ->map(fn (UsdEntry $e) => [
                    'title' => $e->description,
                    'hint' => trim(($e->entry_date?->format('j M Y') ?? '').' · '.UsdEntry::categoryLabel($e->category).' · $'.number_format(abs($e->amount) / 100, 2)),
                    'url' => '/usd?q='.urlencode($e->description),
                ])->all()];
        }

        if ($user->can('tasks.view')) {
            $groups[] = ['key' => 'tasks', 'label' => __('কাজ ও reminder'), 'items' => Task::where('title', 'like', $like)
                ->orderByRaw('done_at is not null')->orderBy('due_at')->limit(self::PER_GROUP)->get()
                ->map(fn (Task $t) => ['title' => $t->title, 'hint' => $t->due_at?->format('j M Y, H:i'), 'url' => '/tasks?focus='.$t->id.($t->done_at ? '&view=done' : '')])->all()];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => $g['items'] !== []))]);
    }
}
