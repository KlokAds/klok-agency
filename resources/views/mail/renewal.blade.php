<x-mail::message>
@php
    // Names are typed in (sign-up, Meta): shown as text, never a link or image.
    $client = \App\Notifications\MailText::plain($renewal->client?->name);
    $campaign = \App\Notifications\MailText::plain($renewal->campaign?->name);
@endphp
@if ($event === 'paid')
# Renewal paid: approve the extension

**{{ $client }}** paid for **{{ $renewal->days }} more days** of **{{ $campaign }}**.

<x-mail::table>
| | |
|:--|--:|
| Invoice | {{ $renewal->invoice?->number }} |
| Paid | {{ $total }} |
| Ad budget | {{ $usd }} |
| Ends now | {{ $renewal->ends_on->format('d M Y') }} |
</x-mail::table>

Approve and Klok Pilot moves the campaign's end on Meta by {{ $renewal->days }} days.

<x-mail::button :url="$url">
Review & approve
</x-mail::button>
@elseif ($event === 'extended')
# Renewal paid: campaign extended

**{{ $client }}** paid for **{{ $renewal->days }} more days** of **{{ $campaign }}**, and it now runs to **{{ $renewal->new_end?->format('d M Y') }}** on Meta.

<x-mail::table>
| | |
|:--|--:|
| Invoice | {{ $renewal->invoice?->number }} |
| Paid | {{ $total }} |
| Ad budget | {{ $usd }} |
</x-mail::table>

<x-mail::button :url="$url">
Open in KlokOne
</x-mail::button>
@else
# Contract ended: no extension

**{{ $client }}** ended the contract for **{{ $campaign }}**. It stops on {{ $renewal->ends_on->format('d M Y') }} as planned; nothing needs extending.

<x-mail::button :url="$url">
Open in KlokOne
</x-mail::button>
@endif

KlokOne
</x-mail::message>
