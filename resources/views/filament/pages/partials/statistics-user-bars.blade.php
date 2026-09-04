@php($barRows = collect($rows)->take(8))
@php($barMax = max(1, $barRows->max('total') ?? 1))
@forelse($barRows as $row)
    <div class="stats-bar-row"><span title="{{ $row['name'] }}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $row['name'] }}</span><div class="stats-stack"><i title="Quotes: {{ $row['quotes'] }}" style="width:{{ $row['quotes']/$barMax*100 }}%"></i><i title="Schedules: {{ $row['schedules'] }}" style="width:{{ $row['schedules']/$barMax*100 }}%"></i><i title="Packs: {{ $row['packs'] }}" style="width:{{ $row['packs']/$barMax*100 }}%"></i></div><b>{{ $row['total'] }}</b></div>
@empty
    <p class="stats-muted">No outputs in this period.</p>
@endforelse
<div class="stats-legend"><span><i style="background:#ff9800"></i>Quotes</span><span><i style="background:#39a9db"></i>Schedules</span><span><i style="background:#34c77b"></i>Packs</span></div>
