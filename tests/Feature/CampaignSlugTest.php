<?php

use App\Models\Campaign;
use App\Models\PilotAccount;

it('names a campaign page after the campaign and keeps numbers working', function () {
    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $a = Campaign::create(['pilot_campaign_id' => 901, 'pilot_account_id' => $account->id, 'name' => 'Summer Sale']);
    $b = Campaign::create(['pilot_campaign_id' => 902, 'pilot_account_id' => $account->id, 'name' => 'Summer Sale']);
    $c = Campaign::create(['pilot_campaign_id' => 903, 'pilot_account_id' => $account->id, 'name' => 'ঈদ অফার']);

    expect($a->slug)->toBe('summer-sale')
        ->and($b->slug)->toBe('summer-sale-2')
        ->and($c->slug)->toBe('eed-ofar');

    $a->update(['name' => 'Winter Sale']);
    expect($a->fresh()->slug)->toBe('winter-sale');

    expect((new Campaign)->resolveRouteBinding('winter-sale')?->id)->toBe($a->id)
        ->and((new Campaign)->resolveRouteBinding((string) $b->id)?->id)->toBe($b->id)
        ->and((new Campaign)->resolveRouteBinding('nothing-here'))->toBeNull();
});
