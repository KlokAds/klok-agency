<x-mail::message>
# Invoice {{ $invoice->number }}

{{ $invoice->client?->name }},

{{ $business['name'] }} — invoice {{ $invoice->number }}, {{ $invoice->issue_date->format('d M Y') }}.

<x-mail::table>
| | |
|:--|--:|
| Total | {{ $total }} |
| Due | {{ $due }} |
@if ($invoice->due_date)
| Due date | {{ $invoice->due_date->format('d M Y') }} |
@endif
</x-mail::table>

<x-mail::button :url="$url">
View & pay
</x-mail::button>

{{ $business['name'] }}@if ($business['phone']) · {{ $business['phone'] }}@endif

</x-mail::message>
