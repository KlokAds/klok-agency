<?php

namespace App\Http\Controllers;

use App\Notifications\AgencyAlert;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A feature request, an idea or a problem, from anyone signed in (a client on the portal or one
 * of the team): every Owner gets it whole, in the bell, on the phone and by email.
 */
class FeedbackController extends Controller
{
    public const TITLES = [
        'feature' => 'নতুন feature-এর অনুরোধ', // i18n
        'idea' => 'নতুন পরামর্শ', // i18n
        'problem' => 'সমস্যার খবর', // i18n
    ];

    public function store(Request $request, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(self::TITLES))],
            'text' => ['required', 'string', 'min:5', 'max:2000'],
        ], [], ['text' => __('লেখা')]);

        $user = $request->user();
        $where = $user->client?->name;

        $notifier->send(null, new AgencyAlert('feedback', self::TITLES[$data['kind']], '{who}{where}: {text}', [
            'who' => $user->name,
            'where' => $where !== null ? ' ('.$where.')' : '',
            'text' => trim($data['text']),
        ], ['url' => $where !== null ? '/clients/'.$user->client_id : '/notifications']));

        $this->toast(__('পাঠানো হয়েছে — ধন্যবাদ! আমরা দেখে জানাব।'));

        return back();
    }
}
