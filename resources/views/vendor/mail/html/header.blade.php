@props(['url'])
{{-- KlokOne's mark and name: drawn in HTML, so it shows even where mail blocks images. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table class="brand" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-mark">K</td>
<td class="brand-name">KlokOne<br><span class="brand-tag">Klok Ads · {{ __('হিসাবের খাতা') }}</span></td>
</tr>
</table>
</a>
</td>
</tr>
