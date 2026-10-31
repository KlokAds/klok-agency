@props(['url'])
{{-- KlokOne's mark and name: drawn in HTML, so it shows even where mail blocks images. --}}
<tr>
<td class="header">
<table class="brand" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-mark"><a href="{{ $url }}" style="color: #c2a3df; text-decoration: none;">K</a></td>
<td class="brand-name"><a href="{{ $url }}" style="color: #16141f; text-decoration: none;">KlokOne</a><br><span class="brand-tag">Klok Ads · {{ __('হিসাবের খাতা') }}</span></td>
</tr>
</table>
</td>
</tr>
