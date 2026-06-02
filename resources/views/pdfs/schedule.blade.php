@extends('pdfs.layouts.master')

@section('title', 'Lighting Schedule — ' . ($project->reference_number ?? $project->name))

{{-- ── Column widths (A4 portrait, 180 mm content width) ──────────────────
     #      Code    Ref    Description  Qty    W      lm     Unit£  Total£ Notes
     5mm    24mm    16mm   52mm         9mm    12mm   12mm   18mm   18mm   14mm
     = 180 mm total
──────────────────────────────────────────────────────────────────────────── --}}

@section('styles')
/* ── Typography ───────────────────────────────────────────────────────── */
h1, h2, h3 { font-weight: 700; }

/* ── Document header ──────────────────────────────────────────────────── */
.doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6mm;
    padding-bottom: 4mm;
    border-bottom: 2pt solid #192542;
    gap: 8mm;
}

.doc-header-brand {
    flex: 0 0 auto;
}

.brand-name {
    display: block;
    font-size: 20pt;
    font-weight: 800;
    color: #192542;
    letter-spacing: -0.5pt;
    line-height: 1;
}

.brand-tagline {
    display: block;
    font-size: 8pt;
    font-weight: 400;
    color: #6b7280;
    letter-spacing: 1pt;
    text-transform: uppercase;
    margin-top: 1mm;
}

.doc-header-meta {
    flex: 1 1 auto;
    display: flex;
    justify-content: flex-end;
}

.meta-grid {
    display: grid;
    grid-template-columns: auto auto;
    column-gap: 4mm;
    row-gap: 0.8mm;
    font-size: 7.5pt;
}

.meta-label {
    color: #6b7280;
    font-weight: 600;
    white-space: nowrap;
    text-align: right;
}

.meta-value {
    color: #111827;
    font-weight: 400;
}

.meta-value.emphasis {
    font-weight: 700;
    color: #192542;
}

/* ── Area block ───────────────────────────────────────────────────────── */
.area-block {
    margin-bottom: 5mm;
    page-break-inside: avoid;
}

.area-header {
    background-color: #192542;
    color: #ffffff;
    padding: 2mm 3mm;
    font-size: 8.5pt;
    font-weight: 700;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.area-header-meta {
    font-size: 7pt;
    font-weight: 400;
    opacity: 0.8;
}

/* ── Schedule table ───────────────────────────────────────────────────── */
.schedule-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.5pt;
}

.schedule-table thead tr {
    background-color: #e8ecf2;
    color: #192542;
}

.schedule-table th {
    padding: 1.5mm 2mm;
    font-weight: 700;
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    border-bottom: 1pt solid #192542;
    white-space: nowrap;
}

.schedule-table td {
    padding: 1.5mm 2mm;
    vertical-align: top;
    border-bottom: 0.5pt solid #e5e7eb;
    color: #111827;
}

/* Row shading */
.schedule-table tbody tr:nth-child(even) td {
    background-color: #f9fafb;
}

/* Modified / Custom line type highlight */
.row-modified td { border-left: 2pt solid #f59e0b; }
.row-custom    td { border-left: 2pt solid #8b5cf6; }

/* Subtotal row */
.schedule-table tfoot tr td {
    background-color: #e8ecf2;
    font-weight: 700;
    color: #192542;
    border-top: 1pt solid #192542;
    border-bottom: none;
    padding: 1.5mm 2mm;
}

/* ── Column widths ────────────────────────────────────────────────────── */
.col-num   { width: 2.8%;  text-align: right; color: #9ca3af; }
.col-code  { width: 13.3%; }
.col-ref   { width: 8.9%;  }
.col-desc  { width: 28.9%; }
.col-qty   { width: 5%;    text-align: right; }
.col-watt  { width: 6.7%;  text-align: right; }
.col-lm    { width: 6.7%;  text-align: right; }
.col-price { width: 10%;   text-align: right; }
.col-total { width: 10%;   text-align: right; font-weight: 600; }
.col-notes { width: 7.8%;  color: #4b5563; font-style: italic; }

th.col-qty, th.col-watt, th.col-lm, th.col-price, th.col-total {
    text-align: right;
}

/* Muted placeholder for empty/null values */
.muted { color: #d1d5db; }

/* ── Grand total strip ────────────────────────────────────────────────── */
.grand-total {
    margin-top: 4mm;
    display: flex;
    justify-content: flex-end;
}

.grand-total-box {
    border: 1.5pt solid #192542;
    border-radius: 1mm;
    overflow: hidden;
    min-width: 70mm;
}

.grand-total-box table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
}

.grand-total-box td {
    padding: 1.5mm 3mm;
    border-bottom: 0.5pt solid #e5e7eb;
}

.grand-total-box td:last-child {
    text-align: right;
    font-weight: 700;
    color: #192542;
}

.grand-total-box tr:last-child td { border-bottom: none; }

.grand-total-box .gt-heading {
    background-color: #192542;
    color: #fff;
    font-weight: 700;
    font-size: 8pt;
    padding: 2mm 3mm;
}

/* ── Notes / quote section ────────────────────────────────────────────── */
.doc-notes {
    margin-top: 6mm;
    padding: 3mm;
    border: 0.5pt solid #e5e7eb;
    border-radius: 1mm;
    font-size: 7.5pt;
    color: #374151;
    page-break-inside: avoid;
}

.doc-notes h3 {
    font-size: 7.5pt;
    font-weight: 700;
    color: #192542;
    margin-bottom: 2mm;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
}
@endsection

@section('footer-left')
    {{ $project->name }}
    @if($project->reference_number) · Ref {{ $project->reference_number }}@endif
    · Schedule R{{ $revision->revision_number }}
    · {{ now()->format('d M Y') }}
@endsection

@section('content')

{{-- ── Document header ─────────────────────────────────────────────────── --}}
<div class="doc-header">
    <div class="doc-header-brand">
        <span class="brand-name">Tamlite</span>
        <span class="brand-tagline">Lighting Schedule</span>
    </div>

    <div class="doc-header-meta">
        <div class="meta-grid">
            <span class="meta-label">Project</span>
            <span class="meta-value emphasis">{{ $project->name }}</span>

            @if($project->reference_number)
            <span class="meta-label">Reference</span>
            <span class="meta-value">{{ $project->reference_number }}</span>
            @endif

            @if($project->customer_name)
            <span class="meta-label">Customer</span>
            <span class="meta-value">{{ $project->customer_name }}</span>
            @endif

            @if($project->contractor)
            <span class="meta-label">Contractor</span>
            <span class="meta-value">{{ $project->contractor }}</span>
            @endif

            @if($project->site_location)
            <span class="meta-label">Site</span>
            <span class="meta-value">{{ $project->site_location }}</span>
            @endif

            <span class="meta-label">Revision</span>
            <span class="meta-value">R{{ $revision->revision_number }}</span>

            <span class="meta-label">Date</span>
            <span class="meta-value">
                {{ $project->date?->format('d M Y') ?? now()->format('d M Y') }}
            </span>

            @if($project->user)
            <span class="meta-label">Prepared by</span>
            <span class="meta-value">{{ $project->user->name }}</span>
            @endif
        </div>
    </div>
</div>

{{-- ── Areas ────────────────────────────────────────────────────────────── --}}
@foreach ($areas as $area)
    @if($area->lines->isNotEmpty())
    <div class="area-block">
        <div class="area-header">
            <span>{{ $loop->iteration }}. {{ $area->name }}</span>
            <span class="area-header-meta">
                {{ $area->lines->count() }} line{{ $area->lines->count() === 1 ? '' : 's' }}
                &nbsp;·&nbsp;
                Total qty: {{ $area->line_total_qty }}
            </span>
        </div>

        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="col-code">Code</th>
                    <th class="col-ref">Ref</th>
                    <th class="col-desc">Description</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-watt">W</th>
                    <th class="col-lm">lm</th>
                    <th class="col-price">Unit £</th>
                    <th class="col-total">Total £</th>
                    <th class="col-notes">Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($area->lines as $line)
                    @php
                        $lineTotal = ($line->qty ?? 0) * (float) ($line->unit_price ?? 0);
                        $typeClass = match($line->type) {
                            \App\Enums\ProjectLineType::Modified => 'row-modified',
                            \App\Enums\ProjectLineType::Custom   => 'row-custom',
                            default                              => '',
                        };
                    @endphp
                    <tr class="{{ $typeClass }}">
                        <td class="col-num muted">{{ $loop->iteration }}</td>
                        <td class="col-code">{{ $line->code ?? '' }}</td>
                        <td class="col-ref">{{ $line->ref ?? '' }}</td>
                        <td class="col-desc">{{ $line->description ?? '' }}</td>
                        <td class="col-qty">{{ $line->qty ?? '' }}</td>
                        <td class="col-watt">
                            @if($line->product?->luminaire_wattage_w)
                                {{ $line->product->luminaire_wattage_w }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="col-lm">
                            @if($line->product?->lumens_lm)
                                {{ $line->product->lumens_lm }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="col-price">
                            @if($line->unit_price)
                                £{{ number_format((float) $line->unit_price, 2) }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="col-total">
                            @if($line->unit_price)
                                £{{ number_format($lineTotal, 2) }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="col-notes">{{ $line->notes ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;">Area subtotal</td>
                    <td class="col-qty">{{ $area->line_total_qty }}</td>
                    <td class="col-watt"></td>
                    <td class="col-lm"></td>
                    <td class="col-price"></td>
                    <td class="col-total">
                        @if($area->line_total > 0)
                            £{{ number_format($area->line_total, 2) }}
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif
@endforeach

{{-- ── Grand total ──────────────────────────────────────────────────────── --}}
@php
    $grandQty   = $areas->sum('line_total_qty');
    $grandTotal = $areas->sum('line_total');
@endphp
<div class="grand-total">
    <div class="grand-total-box">
        <div class="gt-heading">Project Total</div>
        <table>
            <tr>
                <td>Total line items</td>
                <td>{{ $areas->sum(fn ($a) => $a->lines->count()) }}</td>
            </tr>
            <tr>
                <td>Total qty</td>
                <td>{{ $grandQty }}</td>
            </tr>
            <tr>
                <td>Total value</td>
                <td>
                    @if($grandTotal > 0)
                        £{{ number_format($grandTotal, 2) }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- ── Quote / general notes ────────────────────────────────────────────── --}}
@if($project->quote_notes || $project->general_notes)
<div class="doc-notes">
    @if($project->quote_notes)
        <h3>Quote Notes</h3>
        <p>{{ $project->quote_notes }}</p>
        @if($project->general_notes)<br>@endif
    @endif
    @if($project->general_notes)
        <h3>General Notes</h3>
        <p>{{ $project->general_notes }}</p>
    @endif
</div>
@endif

@endsection
