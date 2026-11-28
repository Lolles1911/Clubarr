@props(['values', 'labels' => null, 'unit' => 'h', 'height' => '7rem', 'every' => 1])
{{-- A simple bar chart in plain HTML: no scripts. $values: label => number. --}}
@php($max = max(array_values($values) ?: [0]) ?: 1)
<div style="display:flex;align-items:flex-end;gap:2px;height:{{ $height }};padding-top:.25rem">
    @foreach ($values as $label => $value)
        <div title="{{ $label }}: {{ $value }} {{ $unit }}" style="flex:1;min-width:2px;height:{{ max(2, round($value / $max * 100)) }}%;background:var(--primary-500);border-radius:3px 3px 0 0;opacity:{{ $value > 0 ? 1 : .25 }}"></div>
    @endforeach
</div>
<div style="display:flex;gap:2px;font-size:.65rem;opacity:.6;margin-top:.2rem">
    @foreach (array_keys($values) as $i => $label)
        <div style="flex:1;min-width:2px;text-align:center;overflow:hidden;white-space:nowrap">{{ $i % $every === 0 ? ($labels[$i] ?? $label) : '' }}</div>
    @endforeach
</div>
