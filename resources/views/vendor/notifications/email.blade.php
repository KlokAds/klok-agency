<x-mail::message>
{{-- What this is about, small, above the title --}}
@isset($kicker)
<p class="kicker">{{ $kicker }}</p>
@endisset

{{-- Title --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
# {{ __('KlokOne থেকে খবর') }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Signature --}}
<p class="signature">{{ ! empty($salutation) ? $salutation : __('শুভেচ্ছান্তে,') }}<br>{{ __('Klok Ads টিম') }}</p>

{{-- A short way in when the button does not open --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('Button না খুললে') }} <a href="{{ $actionUrl }}">{{ __('এখানে চাপুন') }}</a> — {{ parse_url($actionUrl, PHP_URL_HOST) }}
</x-slot:subcopy>
@endisset
</x-mail::message>
