@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    // One solid colour on the cell and on the link, inline: Gmail's dark mode then shows one clean
    // button, not a lighter box with a highlighted word inside it.
    $bg = ['success' => '#15803d', 'error' => '#b91c1c'][$color] ?? '#0f766e';
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}" style="padding: 8px 0 4px;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" bgcolor="{{ $bg }}" style="background-color: {{ $bg }}; border-radius: 10px;">
<a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block; padding: 13px 28px; font-size: 15px; font-weight: bold; line-height: 20px; color: #ffffff; text-decoration: none; border-radius: 10px; background-color: {{ $bg }};">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
