<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
KlokOne
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**Klok Ads** · [billing.klokads.com]({{ config('app.url') }})

{{ __('এই email KlokOne থেকে নিজে থেকে এসেছে। কোন খবর email-এ আসবে, Profile-এ বদলাতে পারেন।') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
