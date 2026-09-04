<x-filament-panels::page>
    <style>
        .stats-panel{background:#18191d;border:1px solid #30323a;border-radius:12px;padding:1.25rem}.stats-grid{display:grid;gap:1rem}.stats-kpis{grid-template-columns:repeat(auto-fit,minmax(145px,1fr))}.stats-kpi strong{display:block;font-size:1.65rem;color:#fff}.stats-kpi span,.stats-muted{color:#9ca3af;font-size:.82rem}.stats-filters{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:.75rem;align-items:end}.stats-input{width:100%;height:2.5rem;border:1px solid #444751;border-radius:8px;background:#20232a;color:#fff;padding:.45rem .65rem}.stats-preset.active{background:#d1d5db;border-color:#d1d5db;color:#111}.stats-btn{height:2.5rem;border-radius:8px;padding:0 .9rem;background:#ef8500;color:#111;font-weight:700}.stats-tabs{display:flex;gap:.35rem;border-bottom:1px solid #30323a}.stats-tab{padding:.7rem 1rem;color:#aeb4c0;border-bottom:2px solid transparent}.stats-tab.active{color:#fff;border-color:#ff9800}.stats-two{grid-template-columns:repeat(2,minmax(0,1fr))}.stats-table{width:100%;border-collapse:collapse}.stats-table th,.stats-table td{text-align:left;padding:.65rem;border-bottom:1px solid #30323a}.stats-table th{color:#9ca3af;font-size:.75rem;text-transform:uppercase}.stats-bar-row{display:grid;grid-template-columns:150px 1fr 45px;gap:.7rem;align-items:center;margin:.6rem 0}.stats-bar{height:.65rem;background:#2b2e35;border-radius:999px;overflow:hidden}.stats-bar i{display:block;height:100%;background:#ff9800}.stats-stack{display:flex;height:1rem;border-radius:999px;overflow:hidden;background:#292c33}.stats-stack i:nth-child(1){background:#ff9800}.stats-stack i:nth-child(2){background:#39a9db}.stats-stack i:nth-child(3){background:#34c77b}.stats-chart{min-height:260px}.stats-chart svg{display:block;width:100%;height:260px;overflow:visible}.stats-legend{display:flex;gap:1rem;flex-wrap:wrap;color:#b8bec9;font-size:.78rem}.stats-legend i{display:inline-block;width:.65rem;height:.65rem;border-radius:50%;margin-right:.3rem}.stats-warning{padding:1rem;border:1px solid #7f1d1d;background:#2b1518;border-radius:8px;color:#fecaca}.stats-loading{position:fixed;inset:0;z-index:60;align-items:center;justify-content:center;gap:.8rem;background:rgba(8,9,11,.72);color:#fff;font-weight:700;backdrop-filter:blur(1px)}.stats-spinner{width:2rem;height:2rem;border:3px solid #6b7280;border-top-color:#ff9800;border-radius:50%;animation:stats-spin .75s linear infinite}.stats-paginated [x-cloak]{display:none!important}.stats-pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-top:.85rem}.stats-pagination-actions{display:flex;align-items:center;gap:.4rem}.stats-page-btn{border:1px solid #444751;border-radius:7px;background:#20232a;color:#fff;padding:.35rem .65rem}.stats-page-btn:hover:not(:disabled){border-color:#ff9800;color:#ffb13b}.stats-page-btn:disabled{cursor:not-allowed;opacity:.4}.stats-page-position{min-width:6.5rem;text-align:center;color:#d1d5db;font-size:.82rem}@keyframes stats-spin{to{transform:rotate(360deg)}}@media(max-width:1000px){.stats-filters{grid-template-columns:repeat(2,1fr)}.stats-two{grid-template-columns:1fr}.stats-pagination{align-items:flex-start;flex-direction:column}.stats-pagination-actions{flex-wrap:wrap}}
    </style>

    <div class="stats-loading" wire:loading.delay wire:target="refreshReport,setPreset" role="status" aria-live="polite">
        <span class="stats-spinner" aria-hidden="true"></span>
        <span>Loading statistics…</span>
    </div>

    <form wire:submit="refreshReport" class="stats-panel stats-filters">
        <label><span class="stats-muted">From</span><input class="stats-input" type="date" wire:model.change="from"></label>
        <label><span class="stats-muted">To</span><input class="stats-input" type="date" wire:model.change="to"></label>
        <label><span class="stats-muted">Group by</span><select class="stats-input" wire:model="groupBy"><option value="day">Day</option><option value="week">Week</option><option value="month">Month</option></select></label>
        <label><span class="stats-muted">User</span><select class="stats-input" wire:model="userId"><option value="">All users</option>@foreach($this->userOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label><span class="stats-muted">Owner</span><select class="stats-input" wire:model="ownerEmail"><option value="">All owners</option>@foreach($this->ownerOptions as $email => $name)<option value="{{ $email }}">{{ $name }}</option>@endforeach</select></label>
        <label><span class="stats-muted">Currency</span><select class="stats-input" wire:model="currency"><option value="">All currencies</option>@foreach($this->currencyOptions as $code)<option value="{{ $code }}">{{ \App\Models\Project::symbolForCurrency($code) }}</option>@endforeach</select></label>
        <div style="grid-column:1/-1;display:flex;gap:.5rem;flex-wrap:wrap">
            @foreach(['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter'] as $preset => $label)
                <button type="button" class="stats-input stats-preset {{ $activePreset === $preset ? 'active' : '' }}" style="width:auto" wire:click="setPreset('{{ $preset }}')" wire:loading.attr="disabled" aria-pressed="{{ $activePreset === $preset ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
            <button class="stats-btn" type="submit" wire:loading.attr="disabled">Apply filters</button>
        </div>
    </form>

    @if($reportError)<div class="stats-warning">{{ $reportError }}</div>
    @elseif($report)
        <nav class="stats-tabs">@foreach(['overview'=>'Overview','usage'=>'Usage','projects'=>'Projects','outputs'=>'Outputs'] as $key=>$label)<button type="button" wire:click="$set('section','{{ $key }}')" class="stats-tab {{ $section === $key ? 'active' : '' }}">{{ $label }}</button>@endforeach</nav>

        @if($section === 'overview')
            <div class="stats-grid stats-kpis">
                @foreach(['projects_created'=>'Projects created','quotes'=>'Quote PDFs','schedules'=>'Schedules','document_packs'=>'Document packs','logins'=>'Logins','active_users'=>'Active users'] as $key=>$label)<div class="stats-panel stats-kpi"><strong>{{ number_format($report['summary'][$key]) }}</strong><span>{{ $label }}</span></div>@endforeach
                <div class="stats-panel stats-kpi"><strong>{{ $report['summary']['median_first_quote_hours'] === null ? '—' : number_format($report['summary']['median_first_quote_hours'],1).'h' }}</strong><span>Median to first quote</span></div>
                <div class="stats-panel stats-kpi"><strong>{{ number_format($report['summary']['quote_regeneration_rate'],1) }}%</strong><span>Quote regeneration rate</span></div>
            </div>
            @if($report['financials'])<div class="stats-grid stats-kpis">@foreach($report['financials'] as $code=>$money)<div class="stats-panel stats-kpi"><strong>{{ \App\Models\Project::symbolForCurrency($code) }}{{ number_format($money['gross'],2) }}</strong><span>Total gross quoted</span></div><div class="stats-panel stats-kpi"><strong>{{ \App\Models\Project::symbolForCurrency($code) }}{{ number_format($money['net'],2) }}</strong><span>Total net quoted</span></div><div class="stats-panel stats-kpi"><strong>{{ $money['cover'] === null ? '—' : number_format($money['cover'],1).'%' }}</strong><span>Value-weighted average cover</span></div>@endforeach</div>@endif
            <div class="stats-grid stats-two">
                <section class="stats-panel"><h3>Activity over time</h3>@include('filament.pages.partials.statistics-line-chart',['rows'=>$report['trend']])</section>
                <section class="stats-panel"><h3>Project status funnel</h3>@php($max=max(1,collect($report['status_funnel'])->max('count')))@foreach($report['status_funnel'] as $row)<div class="stats-bar-row"><span>{{ $row['label'] }}</span><div class="stats-bar"><i style="width:{{ $row['count']/$max*100 }}%"></i></div><b>{{ $row['count'] }}</b></div>@endforeach<p class="stats-muted">Approval Requested is optional. Later stages are counted as having passed earlier stages.</p></section>
            </div>
            @php($outputTotal=max(1,$report['summary']['quotes']+$report['summary']['schedules']+$report['summary']['document_packs']))
            <section class="stats-panel"><h3>Output mix</h3><div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap"><div aria-label="Output mix chart" style="width:130px;height:130px;border-radius:50%;background:conic-gradient(#ff9800 0 {{ $report['summary']['quotes']/$outputTotal*100 }}%,#39a9db 0 {{ ($report['summary']['quotes']+$report['summary']['schedules'])/$outputTotal*100 }}%,#34c77b 0)"></div><div><p><b style="color:#ff9800">●</b> Quotes — {{ $report['summary']['quotes'] }}</p><p><b style="color:#39a9db">●</b> Schedules — {{ $report['summary']['schedules'] }}</p><p><b style="color:#34c77b">●</b> Document packs — {{ $report['summary']['document_packs'] }}</p></div></div></section>
            <section class="stats-panel"><h3>Top output producers</h3>@include('filament.pages.partials.statistics-user-bars',['rows'=>$report['outputs_by_user']])</section>
        @elseif($section === 'usage')
            <div class="stats-grid stats-two">
                @foreach(['logins_by_user'=>'Logins by user','projects_by_user'=>'Projects created by user','projects_by_owner'=>'Projects created for owner'] as $key=>$title)
                    <section class="stats-panel">
                        <h3>{{ $title }}</h3>
                        <x-statistics.paginated-table :count="count($report[$key])">
                            <table class="stats-table">
                                <thead><tr><th>User / owner</th><th>Total</th></tr></thead>
                                <tbody>
                                    @forelse($report[$key] as $row)
                                        <tr x-show="visible({{ $loop->index }})" x-cloak><td>{{ $row['name'] }}</td><td>{{ $row['count'] }}</td></tr>
                                    @empty
                                        <tr><td colspan="2">No activity in this period.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </x-statistics.paginated-table>
                    </section>
                @endforeach
            </div>
            <section class="stats-panel"><h3>Outputs by user</h3>@include('filament.pages.partials.statistics-output-table',['rows'=>$report['outputs_by_user']])</section>
        @elseif($section === 'projects')
            <div class="stats-grid stats-kpis"><div class="stats-panel stats-kpi"><strong>{{ $report['summary']['average_revision_interval_days'] === null ? '—' : number_format($report['summary']['average_revision_interval_days'],1).' days' }}</strong><span>Average time between revisions</span></div><div class="stats-panel stats-kpi"><strong>{{ count($report['never_quoted']) }}</strong><span>Created but never quoted</span></div></div>
            <section class="stats-panel">
                <h3>Project details</h3>
                <x-statistics.paginated-table :count="count($report['project_rows'])">
                    <div style="overflow:auto">
                        <table class="stats-table">
                            <thead><tr><th>Reference</th><th>Project</th><th>Creator</th><th>Owner</th><th>Status</th><th>Rev</th><th>Tenders</th><th>Quotes</th><th>Schedules</th>@if(auth()->user()->can('pricing.view'))<th>Cover</th><th>Net</th><th>Gross</th>@endif</tr></thead>
                            <tbody>
                                @forelse($report['project_rows'] as $row)
                                    <tr x-show="visible({{ $loop->index }})" x-cloak><td>{{ $row['reference'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['creator'] }}</td><td>{{ $row['owner'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['revisions'] }}</td><td>{{ $row['tenders'] }}</td><td>{{ $row['quotes'] }}</td><td>{{ $row['schedules'] }}</td>@if(auth()->user()->can('pricing.view'))<td>{{ $row['has_cover'] ? ($row['cover'] === null ? 'Yes' : number_format($row['cover'],1).'%') : 'No' }}</td><td>{{ \App\Models\Project::symbolForCurrency($row['currency']) }}{{ number_format($row['net'],2) }}</td><td>{{ \App\Models\Project::symbolForCurrency($row['currency']) }}{{ number_format($row['gross'],2) }}</td>@endif</tr>
                                @empty
                                    <tr><td colspan="12">No projects in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-statistics.paginated-table>
            </section>
            <div class="stats-grid stats-two">
                <section class="stats-panel">
                    <h3>Projects never quoted</h3>
                    <x-statistics.paginated-table :count="count($report['never_quoted'])">
                        <table class="stats-table">
                            <thead><tr><th>Reference</th><th>Project</th><th>Owner</th></tr></thead>
                            <tbody>
                                @forelse($report['never_quoted'] as $row)
                                    <tr x-show="visible({{ $loop->index }})" x-cloak><td>{{ $row['reference'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['owner'] }}</td></tr>
                                @empty
                                    <tr><td colspan="3">None.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-statistics.paginated-table>
                </section>
                @if(auth()->user()->can('pricing.view'))
                    <section class="stats-panel">
                        <h3>High-value projects with no recent activity</h3>
                        <x-statistics.paginated-table :count="count($report['high_value_inactive'])">
                            <table class="stats-table">
                                <thead><tr><th>Project</th><th>Gross</th><th>Last activity</th></tr></thead>
                                <tbody>
                                    @forelse($report['high_value_inactive'] as $row)
                                        <tr x-show="visible({{ $loop->index }})" x-cloak><td>{{ $row['project']->reference_number }} — {{ $row['project']->name }}</td><td>{{ $row['project']->formatCurrency($row['gross']) }}</td><td>{{ \Carbon\Carbon::parse($row['last_activity'])->format('d M Y') }}</td></tr>
                                    @empty
                                        <tr><td colspan="3">None.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </x-statistics.paginated-table>
                    </section>
                @endif
            </div>
        @else
            <div class="stats-grid stats-two"><section class="stats-panel"><h3>Outputs by owner</h3>@include('filament.pages.partials.statistics-output-table',['rows'=>$report['outputs_by_owner']])</section><section class="stats-panel"><h3>Output options</h3><table class="stats-table"><thead><tr><th>Option</th><th>Yes</th><th>No</th></tr></thead><tbody><tr><td>Schedule datasheets</td><td>{{ $report['schedule_options']['include_datasheets']['yes'] }}</td><td>{{ $report['schedule_options']['include_datasheets']['no'] }}</td></tr><tr><td>Quote datasheets</td><td>{{ $report['quote_options']['include_datasheets']['yes'] }}</td><td>{{ $report['quote_options']['include_datasheets']['no'] }}</td></tr><tr><td>Quote cover letter</td><td>{{ $report['quote_options']['include_cover_letter']['yes'] }}</td><td>{{ $report['quote_options']['include_cover_letter']['no'] }}</td></tr><tr><td>Quote legal page</td><td>{{ $report['quote_options']['include_legal_page']['yes'] }}</td><td>{{ $report['quote_options']['include_legal_page']['no'] }}</td></tr></tbody></table><p class="stats-muted">{{ $report['quote_tenders']['total'] }} tenders across quote batches; {{ $report['quote_tenders']['average'] }} average per batch.</p></section></div>
            <section class="stats-panel">
                <h3>Most frequently quoted products</h3>
                <x-statistics.paginated-table :count="count($report['products'])">
                    <table class="stats-table">
                        <thead><tr><th>Code</th><th>Description</th><th>Quote batches</th><th>Quantity</th></tr></thead>
                        <tbody>
                            @forelse($report['products'] as $product)
                                <tr x-show="visible({{ $loop->index }})" x-cloak><td>{{ $product->code }}</td><td>{{ $product->description }}</td><td>{{ $product->quote_count }}</td><td>{{ $product->quantity }}</td></tr>
                            @empty
                                <tr><td colspan="4">No quoted products in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-statistics.paginated-table>
            </section>
            <section class="stats-panel"><h3>Top quoted products</h3>@include('filament.pages.partials.statistics-product-bars',['rows'=>$report['products']])</section>
        @endif
        @if($report['data_since'])<p class="stats-muted">Reliable captured reporting data starts {{ \Carbon\Carbon::parse($report['data_since'])->format('d M Y H:i') }}. Older retained activity is backfilled during deployment.</p>@endif
    @endif
</x-filament-panels::page>
