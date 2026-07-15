{{--
    Tamlite Lighting Schedule / Quote PDF
    Standalone template — does not extend master.
    Position:fixed header + footer repeat on every printed page via headless Chrome.
--}}
@php
    $logoPath  = public_path('images/tamlite-logo.png');
    $logoSrc   = file_exists($logoPath)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
        : null;

    $grandQty   = $areas->sum(fn ($a) => $a->lines->sum('qty'));
    $grandItems = $areas->sum(fn ($a) => $a->lines->count());
    $grandTotal = $areas->sum(fn ($a) => $a->lines->sum(fn ($line) => ((int) ($line->qty ?? 0)) * (float) ($line->unit_price ?? 0)));
    $showPrices = $showPrices ?? false;
    $documentTitle = $documentTitle ?? 'Lighting Schedule';
    $areasWithLines = $areas->filter(fn ($area) => $area->lines->isNotEmpty())->values();

    // Build a set of SKUs that actually exist in the products table,
    // so custom/edited codes never get a datasheet link.
    $allCodes    = $areas->flatMap->lines->pluck('code')->filter()->unique()->values();
    $existingSkus = \App\Models\Product::whereIn('sku', $allCodes)->pluck('sku')->flip();

    $pdfTimestamp = now()->timestamp;
    $formatPdfDate = static function (mixed $date, bool $includeTime = false): string {
        if (blank($date)) {
            return '-';
        }

        $date = $date instanceof \Carbon\CarbonInterface
            ? $date
            : \Illuminate\Support\Carbon::parse($date);

        $formatted = e($date->format('j')).'<sup>'.e($date->format('S')).'</sup> '.e($date->format('M Y'));

        if ($includeTime) {
            $formatted .= ' '.e($date->format('H:i'));
        }

        return $formatted;
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentTitle }} &mdash; {{ $project->reference_number ?? $project->name }}</title>
    <style>
        /* ── Page & reset ───────────────────────────────────────────────── */
        @page          { size: A4 portrait; margin: 12mm 0 20mm; }
        @page :first   { margin-top: 0; }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            color: #1a1a1a;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        a { color: inherit; text-decoration: none; }

        /* ── Page header (first page only — not fixed, flows normally) ──── */
        .page-header {
            background: #fff;
        }

        .page-header-inner {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 8mm 14mm 4mm;
            gap: 10mm;
        }

        /* Left: logo + address */
        .header-brand {
            display: flex;
            flex-direction: column;
            gap: 2.5mm;
        }

        .header-logo {
            height: 13mm;
            width: auto;
            align-self: flex-start;
            flex-shrink: 0;
        }

        .header-address {
            font-size: 7.5pt;
            color: #555;
            line-height: 1.55;
        }

        .header-address strong {
            font-size: 8pt;
            color: #1a1a1a;
        }

        /* Right: doc title + ref meta */
        .header-meta {
            text-align: right;
            flex-shrink: 0;
        }

        .doc-title {
            font-size: 13pt;
            font-weight: 700;
            color: #192542;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 2.5mm;
        }

        .ref-lines {
            font-size: 8pt;
            line-height: 1.9;
            text-align: right;
        }

        .ref-label {
            color: #666;
        }

        .ref-val {
            font-weight: 700;
            color: #192542;
        }

        .ref-val sup {
            font-size: 55%;
            line-height: 0;
            vertical-align: super;
        }

        /* Separator rule */
        .header-rule {
            height: 0;
            border-bottom: 1.5pt solid #192542;
            margin: 0 14mm;
        }

        /* ── Fixed page footer (repeats every page) ─────────────────────── */
        /* Footer is rendered via Puppeteer native footer — see controller */

        /* ── Main content (padded to clear fixed header + footer) ────────── */
        .content-wrap {
            padding: 4mm 14mm 5mm;
        }

        /* ── Project info box ────────────────────────────────────────────── */
        .project-box {
            border: 0.75pt solid #d0d5dd;
            border-radius: 1.5mm;
            background: #f9fafb;
            padding: 4mm 5mm;
            margin-bottom: 4mm;
        }

        .project-name {
            font-size: 12pt;
            font-weight: 700;
            color: #192542;
            margin-bottom: 2mm;
        }

        .project-meta-row {
            font-size: 8.5pt;
            color: #333;
            margin-bottom: 1mm;
            line-height: 1.5;
        }

        .project-meta-row:last-child { margin-bottom: 0; }

        .meta-lbl { color: #666; }

        .meta-sep { margin: 0 2.5mm; color: #bbb; }

        .quote-summary {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 5mm;
        }

        .quote-summary-table {
            width: 58mm;
            border-collapse: collapse;
            font-size: 8.5pt;
            break-inside: avoid;
        }

        .quote-summary-table td {
            padding: 1.5mm 2mm;
            border-bottom: 0.5pt solid #e5e7eb;
        }

        .quote-summary-table .label {
            color: #666;
        }

        .quote-summary-table .value {
            text-align: right;
            font-weight: 700;
            color: #192542;
        }

        .quote-summary-table .grand td {
            border-top: 1pt solid #192542;
            border-bottom: none;
            font-size: 10pt;
        }

        /* ── Area block ──────────────────────────────────────────────────── */
        .area-block {
            margin-bottom: 7mm;
        }

        /* Area name row inside <thead> — repeats on every continuation page */
        .area-header-cell {
            background: #f5f7fa;
            border-left: none;
            padding: 0;
        }

        .area-header-content {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 0.7mm 2mm;
            line-height: 1.25;
        }

        .area-name {
            font-size: 8.5pt;
            font-weight: 700;
            color: #192542;
        }

        .area-summary {
            font-size: 7.5pt;
            color: #777;
        }

        /* ── Line table ──────────────────────────────────────────────────── */
        .line-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        .line-table thead tr:last-child {
            border-bottom: 1pt solid #192542;
        }

        .line-table th {
            text-align: left;
            font-size: 7.5pt;
            font-weight: 700;
            color: #192542;
            padding: 4mm 2mm 1.5mm 2mm;
            white-space: nowrap;
        }

        .line-table td {
            padding: 3mm 2mm 2mm;
            vertical-align: top;
            border-bottom: 0.5pt solid #e8eaed;
            border-left: none;
            line-height: 1.4;
        }

        .line-table tr,
        .line-table th {
            border-left: none;
        }

        .line-table tbody:last-child tr:last-child td { border-bottom: none; }

        .line-table tr { break-inside: avoid; }

        .final-line-and-legal {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        /* Column widths */
        .col-code { width: 12%; font-size: 8pt; white-space: nowrap; }
        .col-ref  { width: 5%; }
        .col-desc { width: 52%; }
        .col-qty  { width: 6%;  text-align: center; }
        .col-ds   { width: 5%;  text-align: center; }
        .col-money { width: 9%; text-align: right; white-space: nowrap; }

        th.col-qty { text-align: center; }
        th.col-money { text-align: right; }

        .ds-link {
            color: #1a56db;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4.5mm;
            height: 4.5mm;
        }

        .ds-link svg {
            width: 3.8mm;
            height: 3.8mm;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }

        .line-note-cell {
            padding-top: 1.5mm;
            padding-left: 20%;
            color: #555;
            font-size: 8pt;
            background: #fcfcfd;
        }

        .line-note-cell strong {
            color: #192542;
        }

        /* ── Notes section ───────────────────────────────────────────────── */
        .doc-notes {
            margin-top: 6mm;
            padding: 3mm 4mm;
            border: 0.5pt solid #e5e7eb;
            border-radius: 1mm;
            font-size: 8pt;
            color: #374151;
            break-inside: avoid;
        }

        .doc-notes h3 {
            font-size: 8pt;
            font-weight: 700;
            color: #192542;
            margin-bottom: 1.5mm;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
        }

        /* ── Legal blurb ───────────────────────────────────────────────── */
        .line-table tr.keep-with-legal {
            break-after: avoid;
            page-break-after: avoid;
        }

        .legal-blurb-row {
            break-before: avoid;
            page-break-before: avoid;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .legal-blurb-row td {
            padding: 5mm 0 0;
            border-bottom: none;
        }

        .legal-blurb {
            width: calc(100% - 16mm);
            margin: 0 auto;
            font-size: 6.1pt;
            color: #000;
            line-height: 1.24;
            text-align: center;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .legal-blurb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .legal-blurb-table td {
            padding: 1.6mm 2.2mm;
            border: 0.5pt solid #e5e7eb;
        }

        .legal-blurb-note {
            background: #fff4f2;
        }

        .legal-blurb-controls {
            background: #f8e7d2;
        }

        .legal-blurb p {
            margin: 0;
        }
    </style>
</head>
<body>

{{-- ── Page header (first page only) ─────────────────────────────────────── --}}
<div class="page-header">
    <div class="page-header-inner">

        {{-- Left: logo + company address --}}
        <div class="header-brand">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" class="header-logo" alt="Tamlite">
            @endif
            <div class="header-address">
                <strong>Tamlite Lighting</strong><br>
                Park Farm Industrial Estate, Pipers Road,<br>
                Redditch, Worcestershire, B98 0HU<br>
                01527 526730<br>
                sales@tamlite.co.uk &nbsp;&middot;&nbsp; www.tamlite.co.uk
            </div>
        </div>

        {{-- Right: document title + reference meta --}}
        <div class="header-meta">
            <div class="doc-title">{{ $documentTitle }}</div>
            <div class="ref-lines">
                <div><span class="ref-label">Ref: </span><span class="ref-val">{{ $project->reference_number ?? '-' }}</span></div>
                @if($revision->revision_number > 0)
                    <div><span class="ref-label">Rev: </span><span class="ref-val">{{ $revision->label() }}</span></div>
                @endif
                <div><span class="ref-label">Date: </span><span class="ref-val">{!! $formatPdfDate($project->date) !!}</span></div>
                <div><span class="ref-label">Sales Engineer: </span><span class="ref-val">{{ $salesEngineerName ?? '-' }}</span></div>
                <div><span class="ref-label">Email: </span><span class="ref-val">{{ $salesEngineerEmail ?? $project->owner_email ?? '-' }}</span></div>
            </div>
        </div>

    </div>
    <div class="header-rule"></div>
</div>

{{-- ── Repeating page footer: rendered via Puppeteer native footer (see controller) ── --}}

{{-- ── Main content ────────────────────────────────────────────────────────── --}}
<div class="content-wrap">

    {{-- Project info box --}}
    <div class="project-box">
        <div class="project-name">{{ $project->name }}</div>
        <div class="project-meta-row">
            <span class="meta-lbl">Customer:</span> {{ $project->customer_name ?? '-' }}
        </div>
        <div class="project-meta-row">
            <span class="meta-lbl">Project Location:</span> {{ $project->site_location ?? '-' }}
        </div>
        <div class="project-meta-row">
            @if($revision->revision_number > 0)
                <span class="meta-lbl">Revision:</span> {{ $revision->label() }}
                <span class="meta-sep">&middot;</span>
            @endif
            <span class="meta-lbl">Prepared by:</span> {{ $project->user?->name ?? '-' }}
        </div>
    </div>

    @if($showPrices)
    <div class="quote-summary">
        <table class="quote-summary-table">
            <tr>
                <td class="label">Total quantity</td>
                <td class="value">{{ number_format($grandQty) }}</td>
            </tr>
            <tr>
                <td class="label">Line items</td>
                <td class="value">{{ number_format($grandItems) }}</td>
            </tr>
            <tr class="grand">
                <td class="label">Quote total</td>
                <td class="value">&pound;{{ number_format($grandTotal, 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    @foreach ($areasWithLines as $area)
        @php
            $areaQty = $area->lines->sum('qty');
            $areaTotal = $area->lines->sum(fn ($line) => ((int) ($line->qty ?? 0)) * (float) ($line->unit_price ?? 0));
            $isFinalLineItemArea = $loop->last;
        @endphp
        <div class="area-block">

            <table class="line-table">
                <thead>
                    <tr>
                        <td colspan="{{ $showPrices ? 7 : 5 }}" class="area-header-cell">
                            <div class="area-header-content">
                                <span class="area-name">{{ $area->name }}</span>
                                <span class="area-summary">
                                    {{ $area->lines->count() }} {{ Str::plural('item', $area->lines->count()) }}
                                    &nbsp;&middot;&nbsp;
                                    qty {{ number_format($areaQty) }}
                                    @if($showPrices)
                                        &nbsp;&middot;&nbsp;
                                        &pound;{{ number_format($areaTotal, 2) }}
                                    @endif
                                </span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th class="col-ref">Ref</th>
                        <th class="col-qty">Qty</th>
                        <th class="col-code">Code</th>
                        <th class="col-desc">Description</th>
                        @if($showPrices)
                            <th class="col-money">Unit Price</th>
                            <th class="col-money">Line Total</th>
                        @endif
                        <th class="col-ds">Datasheet</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($area->lines as $line)
                    @php
                        $hasSku = filled($line->code);
                        $hasLineNote = $hasSku && filled($line->notes);
                        $isFinalLineItemRow = $isFinalLineItemArea && $loop->last;
                    @endphp
                    @if($isFinalLineItemRow)
                </tbody>
                <tbody class="final-line-and-legal">
                    @endif
                    <tr @class(['keep-with-legal' => $isFinalLineItemRow])>
                        <td class="col-ref">{!! $hasSku ? e($line->ref ?? '') : '&nbsp;' !!}</td>
                        <td class="col-qty">{!! $hasSku ? e($line->qty ?? '') : '&nbsp;' !!}</td>
                        <td class="col-code">{!! $hasSku ? e($line->code) : '&nbsp;' !!}</td>
                        <td class="col-desc">{!! $hasSku ? e($line->description ?? '') : '&nbsp;' !!}</td>
                        @if($showPrices)
                            @php
                                $unitPrice = (float) ($line->unit_price ?? 0);
                                $lineTotal = ((int) ($line->qty ?? 0)) * $unitPrice;
                            @endphp
                            <td class="col-money">{!! $hasSku ? '&pound;'.e(number_format($unitPrice, 2)) : '&nbsp;' !!}</td>
                            <td class="col-money">{!! $hasSku ? '&pound;'.e(number_format($lineTotal, 2)) : '&nbsp;' !!}</td>
                        @endif
                        <td class="col-ds">
                            @if($hasSku && isset($existingSkus[$line->code]))
                                @php
                                    $sku   = $line->code;
                                    $base  = str_starts_with($sku, 'XC')
                                        ? 'https://xciteledlighting.co.uk/data-sheet/' . urlencode($sku)
                                        : 'https://tamlite.co.uk/data-sheet/' . urlencode($sku);
                                    $dsUrl = $base . '?t=' . $pdfTimestamp . '&source=luxquote';
                                @endphp
                                <a href="{{ $dsUrl }}" target="_blank" class="ds-link" title="Open datasheet in a new tab" aria-label="Open datasheet in a new tab">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M15 3h6v6" />
                                        <path d="M10 14L21 3" />
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                    </svg>
                                </a>
                            @endif
                        </td>
                    </tr>
                    @if($hasLineNote)
                        <tr class="line-note-row">
                            <td colspan="3">&nbsp;</td>
                            <td colspan="{{ $showPrices ? 4 : 2 }}" class="line-note-cell"><strong>Note:</strong> {{ $line->notes }}</td>
                        </tr>
                    @endif
                    @if($isFinalLineItemRow)
                        <tr class="legal-blurb-row">
                            <td colspan="{{ $showPrices ? 7 : 5 }}">
                                <div class="legal-blurb">
                                    <table class="legal-blurb-table">
                                        <tr>
                                            <td class="legal-blurb-note">
                                                <p>
                                                    <strong>NOTE: ALL QUANTITIES MUST BE CROSS REFERENCED AGAINST ANY DRAWINGS AND/OR LIGHTING REPORTS PRIOR TO ORDER</strong><br>
                                                    &bull; Suspension kits available where applicable, please contact Tamlite sales office for further details 01527 517 777.<br>
                                                    &bull; All quantities shown are strictly budgetary at this stage, subject to fully scaled drawings being submitted.<br>
                                                    &bull; Emergency lighting has been designed in accordance with BS [TBC] but is an indicative layout only - additional lighting may be required.
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="legal-blurb-controls">
                                                <p>
                                                    Please note the above project using Tamlite Vision controls requires a professional on-site commissioning service to enable correct operation of all presence/daylight sensors. Any savings outlined in the energy saving calculations will only be achieved upon the completion of this service. The costs quoted for this include setting of all necessary parameters required to allow for correct presence and/or daylight sensitivity at all times of the day/year.
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>

        </div>
    @endforeach

    {{-- Quote / general notes --}}
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

</div>

</body>
</html>
