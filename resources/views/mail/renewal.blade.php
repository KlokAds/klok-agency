<x-mail::message>
@if ($event === 'paid')
# Renewal paid: approve the extension

**{{ $renewal->client?->name }}** paid for **{{ $renewal->days }} more days** of **{{ $renewal->campaign?->name }}**.

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

**{{ $renewal->client?->name }}** paid for **{{ $renewal->days }} more days** of **{{ $renewal->campaign?->name }}**, and it now runs to **{{ $renewal->new_end?->format('d M Y') }}** on Meta.

<x-mail::table>
| | |
|:--|--:|
| Invoice | {{ $renewal->invoice?->number }} |
| Paid | {{ $total }} |
| Ad budget | {{ $usd }} |
</x-mail::table>

<x-mail::button :url="$url">
Open in Klok Finance
</x-mail::button>
@else
# Contract ended: no extension

**{{ $renewal->client?->name }}** ended the contract for **{{ $renewal->campaign?->name }}**. It stops on {{ $renewal->ends_on->format('d M Y') }} as planned; nothing needs extending.

<x-mail::button :url="$url">
Open in Klok Finance
</x-mail::button>
@endif

Klok Finance
</x-mail::message>
