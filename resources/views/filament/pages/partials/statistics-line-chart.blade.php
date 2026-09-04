@php
    $chartRows = collect($rows)->values();
    $series = ['logins' => '#a78bfa', 'projects' => '#ff9800', 'quotes' => '#34c77b', 'schedules' => '#39a9db'];
    $chartMax = max(1, $chartRows->max(fn ($row) => max($row['logins'], $row['projects'], $row['quotes'], $row['schedules'])) ?? 1);
    $pointCount = max(1, $chartRows->count() - 1);
@endphp
<div class="stats-chart">
    @if($chartRows->isEmpty())
        <p class="stats-muted">No activity in this period.</p>
    @else
        <svg viewBox="0 0 1000 260" role="img" aria-label="Line chart of activity over time" preserveAspectRatio="none">
            @foreach([30, 90, 150, 210] as $gridY)<line x1="45" y1="{{ $gridY }}" x2="980" y2="{{ $gridY }}" stroke="#30343c" stroke-width="1" />@endforeach
            @foreach($series as $key => $colour)
                @php($points = $chartRows->map(fn ($row, $index) => (45 + ($index / $pointCount) * 935).','.(220 - (($row[$key] / $chartMax) * 190)))->implode(' '))
                <polyline points="{{ $points }}" fill="none" stroke="{{ $colour }}" stroke-width="4" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" />
            @endforeach
            @foreach($chartRows as $index => $row)
                @if($index === 0 || $index === $chartRows->count() - 1 || $index % max(1, (int) ceil($chartRows->count() / 6)) === 0)
                    <text x="{{ 45 + ($index / $pointCount) * 935 }}" y="248" fill="#8d94a1" font-size="17" text-anchor="middle">{{ $row['label'] }}</text>
                @endif
            @endforeach
        </svg>
        <div class="stats-legend">@foreach($series as $key=>$colour)<span><i style="background:{{ $colour }}"></i>{{ ucfirst($key) }}</span>@endforeach</div>
    @endif
</div>
