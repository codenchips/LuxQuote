@php
    $logoPath = public_path('images/tamlite-logo.png');
    $logoSrc = file_exists($logoPath)
        ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath))
        : null;
    $footerLogosPath = public_path('images/quote-cover-footer-logos.png');
    $footerLogosSrc = file_exists($footerLogosPath)
        ? 'data:image/png;base64,'.base64_encode(file_get_contents($footerLogosPath))
        : null;
    $windSongRegularPath = public_path('fonts/windsong/WindSong-Regular.ttf');
    $windSongMediumPath = public_path('fonts/windsong/WindSong-Medium.ttf');
    $windSongRegularSrc = file_exists($windSongRegularPath)
        ? 'data:font/truetype;base64,'.base64_encode(file_get_contents($windSongRegularPath))
        : null;
    $windSongMediumSrc = file_exists($windSongMediumPath)
        ? 'data:font/truetype;base64,'.base64_encode(file_get_contents($windSongMediumPath))
        : null;

    $contractorLines = collect([
        $contractor['name'] ?? null,
        $contractor['billing_street'] ?? null,
        $contractor['billing_city'] ?? null,
        $contractor['billing_state'] ?? null,
        $contractor['billing_postal_code'] ?? null,
        filled($contractor['phone'] ?? null) ? 'Tel: '.$contractor['phone'] : null,
    ])->filter(fn ($line) => filled($line))->values();

    $reference = $project->reference_number ?? 'Project '.$project->id;
    $revisionLabel = $revision->revision_number > 0 ? $revision->label() : null;
    $quotationReference = filled($revisionLabel) ? "{$reference} / {$revisionLabel}" : $reference;

    $salesEngineerFullName = collect([
        $salesEngineer['first_name'] ?? null,
        $salesEngineer['last_name'] ?? null,
    ])->filter(fn ($part) => filled($part))->implode(' ');
    $salesEngineerName = filled($salesEngineerFullName)
        ? $salesEngineerFullName
        : ($salesEngineer['name'] ?? 'Tamlite Lighting');
    $salesEngineerTitle = $salesEngineer['title'] ?? null;
    $salesEngineerEmail = $salesEngineer['email'] ?? $project->owner_email ?? null;
    $salesEngineerMobile = $salesEngineer['mobile_phone'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Quotation Cover &mdash; {{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }

        @if($windSongRegularSrc)
            @font-face {
                font-family: "WindSong";
                font-style: normal;
                font-weight: 400;
                src: url("{{ $windSongRegularSrc }}") format("truetype");
            }
        @endif

        @if($windSongMediumSrc)
            @font-face {
                font-family: "WindSong";
                font-style: normal;
                font-weight: 500;
                src: url("{{ $windSongMediumSrc }}") format("truetype");
            }
        @endif

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page {
            min-height: 297mm;
            padding: 14mm 15mm 12mm;
            display: flex;
            flex-direction: column;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16mm;
            padding-bottom: 7mm;
            border-bottom: 1.4pt solid #192542;
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 3mm;
            max-width: 76mm;
        }

        .logo {
            width: 20mm;
            height: 13mm;
            object-fit: contain;
            object-position: left top;
        }

        .address {
            color: #4b5563;
            font-size: 8pt;
            line-height: 1.5;
        }

        .address strong {
            color: #111827;
            font-size: 8.5pt;
        }

        .quotation-meta {
            width: 82mm;
            text-align: right;
        }

        .title {
            margin-bottom: 4mm;
            color: #192542;
            font-size: 17pt;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .meta-grid {
            margin-left: auto;
            margin-top: 4mm;
            width: 68mm;
            font-size: 8.5pt;
            line-height: 1.9;
            text-align: right;
        }

        .meta-label {
            color: #6b7280;
        }

        .meta-value {
            color: #192542;
            font-weight: 700;
        }

        .contractor {
            margin-left: auto;
            width: 68mm;
            color: #374151;
            font-size: 8.5pt;
            line-height: 1.45;
        }

        .contractor .name {
            color: #111827;
            font-weight: 700;
        }

        .project-strip {
            margin: 8mm 0 10mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 0.75pt solid #cfd6e0;
            font-size: 9pt;
        }

        .project-strip .strip-item {
            display: grid;
            grid-template-columns: 23mm 1fr;
        }

        .project-strip .strip-item + .strip-item {
            border-left: 0.5pt solid #e5e7eb;
        }

        .project-strip .label,
        .project-strip .value {
            padding: 2mm 3mm;
        }

        .project-strip .label {
            background: #f5f7fb;
            color: #4b5563;
            font-weight: 700;
        }

        .project-strip .value {
            color: #192542;
            font-weight: 700;
        }

        .letter {
            flex: 1;
            max-width: 172mm;
            font-size: 9.5pt;
            line-height: 1.48;
        }

        .letter p {
            margin-bottom: 4.4mm;
        }

        .signoff {
            margin-top: 2mm;
        }

        .signature {
            margin: 1mm 0 3mm;
            color: #192542;
            font-family: "WindSong", "Brush Script MT", "Segoe Script", cursive;
            font-size: 23pt;
            font-weight: 500;
            line-height: 1.05;
            transform: rotate(-1deg);
            transform-origin: left center;
        }

        .sender {
            font-size: 9pt;
            line-height: 1.35;
        }

        .sender strong {
            color: #111827;
        }

        .logo-space {
            margin-top: auto;
            padding-top: 6mm;
            border-top: 0.5pt solid #d1d5db;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        .footer-logos {
            display: block;
            width: 100%;
            height: auto;
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <section class="brand">
                @if($logoSrc)
                    <img src="{{ $logoSrc }}" class="logo" alt="Tamlite">
                @endif
                <div class="address">
                    <strong>Tamlite Lighting</strong><br>
                    Park Farm Industrial Estate, Pipers Road,<br>
                    Redditch, Worcestershire, B98 0HU<br>
                    01527 526730<br>
                    sales@tamlite.co.uk &nbsp;&middot;&nbsp; www.tamlite.co.uk
                </div>
            </section>

            <section class="quotation-meta">
                <div class="title">Project Quotation</div>
                <div class="contractor">
                    @forelse($contractorLines as $index => $line)
                        <div @class(['name' => $index === 0])>{{ $line }}</div>
                    @empty
                        <div class="name">Trade Partner</div>
                    @endforelse
                </div>
                <div class="meta-grid">
                    <div><span class="meta-label">Ref: </span><span class="meta-value">{{ $quotationReference }}</span></div>
                    <div><span class="meta-label">Date: </span><span class="meta-value">{{ $quoteDate->format('jS M Y') }}</span></div>
                </div>
            </section>
        </header>

        <section class="project-strip">
            <div class="strip-item">
                <div class="label">Project</div>
                <div class="value">{{ $project->name }}</div>
            </div>
            <div class="strip-item">
                <div class="label">Customer</div>
                <div class="value">{{ $project->customer_name ?? 'Trade Partner' }}</div>
            </div>
        </section>

        <section class="letter">
            <p>Dear Trade Partner</p>

            <p>Thank you for your valued enquiry. We are pleased to enclose our quotation for the lighting requirements, based on the information provided.</p>

            <p>Please note that all prices quoted are strictly nett for the purposes of this project and are not subject to further discounts, rebates, or settlements. Irrespective of the fulfilment route, any commercial terms between the distributor and the end customer shall not affect the fixed and final pricing, nor give rise to any retrospective adjustment or contribution.</p>

            <p>This quotation should be read in conjunction with any technical submissions or caveats issued by Tamlite Lighting.</p>

            <p>The quotation reflects the quantities and luminaire types interpreted from your enquiry and is valid for 60 days from the date of issue. Prices are subject to Tamlite Lighting's Conditions of Sale, a copy of which is available upon request.</p>

            <p>Please ensure the quoted items meet the project specification, as it remains the customer's responsibility to verify the accuracy of quantities, product types, and compliance. Returns may not be accepted without prior written approval from Tamlite Lighting.</p>

            <p>We trust our proposal meets your requirements. Should you have any questions, wish to discuss the quotation in more detail, or require support with specification compliance or value-engineered options, please contact us.</p>

            <div class="signoff">
                <p>Yours sincerely</p>
                <div class="signature">{{ $salesEngineerName }}</div>
                <div class="sender">
                    {{ $salesEngineerName }}<br>
                    @if(filled($salesEngineerTitle))
                        <strong>{{ $salesEngineerTitle }}</strong><br>
                    @endif
                    @if(filled($salesEngineerEmail))
                        email: {{ $salesEngineerEmail }}<br>
                    @endif
                    @if(filled($salesEngineerMobile))
                        mobile: {{ $salesEngineerMobile }}
                    @endif
                </div>
            </div>
        </section>

        <footer class="logo-space">
            @if($footerLogosSrc)
                <img src="{{ $footerLogosSrc }}" class="footer-logos" alt="Accreditations">
            @endif
        </footer>
    </main>
</body>
</html>
