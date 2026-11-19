<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lighting Design — {{ $project->name }}</title>
    <style>
        @include('pdfs.brand-panel-styles')
        @foreach (['Light' => 300, 'Regular' => 400, 'SemiBold' => 600] as $weight => $number)
        @font-face { font-family: Chivo; src: url(data:font/ttf;base64,{{ $fonts[$weight] }}) format('truetype'); font-weight: {{ $number }}; }
        @endforeach
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; color: #1a2943; font-family: Chivo, Arial, sans-serif; }
        .page { position: relative; width: 210mm; height: 297mm; overflow: hidden; break-after: page; }
        .page:last-child { break-after: auto; }
        .top-band { position: absolute; top: 0; right: 0; width: 115mm; height: 30mm; background: #1a2943; clip-path: polygon(0 0,100% 0,100% 100%,9% 100%); }
        .logo { position: absolute; width: 38mm; height: 28mm; object-fit: contain; right: 60mm; top: .5mm; }
        .eyebrow { color: #d98132; letter-spacing: 1.3pt; font-size: 7pt; font-weight: 500; }
        .cover-heading { position: absolute; top: 49mm; left: 20mm; width: 170mm; }
        h1 { font-size: 25pt; line-height: 1.1; font-weight: 300; margin: 3mm 0 0; overflow-wrap: anywhere; }
        .hero { position: absolute; top: 86mm; width: 210mm; height: 120mm; object-fit: cover; object-position: center; }
        .ribbon { position: absolute; left: 50mm; top: 190mm; width: 160mm; height: 32mm; background: #1a2943; clip-path: polygon(0 0,100% 0,100% 100%,7% 100%); color: white; display: flex; align-items: center; padding: 3mm 9mm 3mm 16mm; gap: 6mm; }
        .ribbon > div { flex: 1; min-width: 0; }
        .ribbon span { display: block; font-size: 7pt; letter-spacing: 1.1pt; margin-bottom: 2mm; }
        .ribbon strong { font-size: 10pt; font-weight: 400; overflow-wrap: anywhere; }
        .tile { position: absolute; top: 228mm; width: 82.5mm; height: 48mm; object-fit: cover; }
        .left { left: 20mm; } .right { right: 20mm; }
        footer { position: absolute; left: 20mm; right: 20mm; bottom: 10mm; border-top: .2mm solid #ccd0d5; padding-top: 2.5mm; font-size: 6.5pt; }
        .bottom-band { position: absolute; right: 0; bottom: 0; width: 115mm; height: 6mm; background: #1a2943; clip-path: polygon(0 0,100% 0,100% 100%,5% 100%); }
        .statement .top-band { width: 62mm; height: 18mm; }
        .statement .logo { width: 16mm; height: 12mm; right: 18mm; top: 3mm; }
        .project-heading { position: absolute; left: 20mm; top: 8mm; width: 125mm; font-size: 9pt; overflow-wrap: anywhere; }
        .statement-heading { position: absolute; left: 20mm; top: 39mm; width: 170mm; }
        h2 { font-size: 28pt; font-weight: 300; margin: 3mm 0 0; border-bottom: .2mm solid #ccd0d5; padding-bottom: 4mm; }
        h2 strong { font-weight: 600; }
        .copy { position: absolute; top: 69mm; left: 20mm; width: 170mm; font-size: 10pt; line-height: 1.45; }
        .copy p { margin: 0; padding: 2.6mm 0; border-bottom: .2mm solid #e2e4e8; }
        .copy p:first-child { padding-top: 0; }
        .copy p:last-child { border: 0; }
        .copy strong { font-weight: 600; }
        .reference { position: absolute; bottom: 10mm; right: 20mm; font-size: 6.5pt; }
    </style>
</head>
<body>
<section class="page">
    @include('pdfs.brand-panel')
    <div class="cover-heading"><div class="eyebrow">LIGHTING DESIGN</div><h1 style="font-size: {{ mb_strlen($project->name) > 110 ? 16 : (mb_strlen($project->name) > 60 ? 20 : 25) }}pt">{{ $project->name }}</h1></div>
    <img class="hero" src="{{ $assets['top.jpg'] }}" alt="">
    <div class="ribbon">
        <div><span>DESIGNED BY</span><strong style="font-size: {{ mb_strlen($project->user?->name ?? '') > 50 ? 7 : 10 }}pt">{{ $project->user?->name ?? '-' }}</strong></div>
        <div><span>PROJECT REFERENCE</span><strong>{{ $project->reference_number }}</strong></div>
        <div><span>DATE</span><strong>{{ $date }}</strong></div>
    </div>
    <img class="tile left" src="{{ $assets['left.jpg'] }}" alt="">
    <img class="tile right" src="{{ $assets['right.jpg'] }}" alt="">
    <footer>Tamlite Lighting · Stafford Park 12, Telford, Shropshire TF3 3BJ</footer>
    <div class="bottom-band"></div>
</section>
<section class="page statement">
    <div class="project-heading">{{ $project->name }}</div>
    <div class="top-band"></div>
    <img class="logo" src="{{ $assets['logo-white.png'] }}" alt="Tamlite">
    <div class="statement-heading"><div class="eyebrow">LIGHTING DESIGN</div><h2>Scheme <strong>statement</strong></h2></div>
    <div class="copy">
        <p>All calculations, unless stated, have been based upon an open plan area without obstruction.</p>
        <p><strong>Unified Glare Rating (UGR) and luminance values</strong> are available for your approval if necessary.</p>
        <p><strong>Emergency scheme</strong> is to be checked and confirmed with a local building fire control officer and ultimately approved by the owner of the building. In designing the initial scheme, we have prejudged areas which are likely to require emergency lighting in compliance with BS 5266 according to a future employer's risk assessment. Before a final emergency lighting scheme can be adopted it will be necessary to review this risk assessment, ensuring coverage of all points of emphasis, such as final locations for firefighting equipment, and anything else relevant to the overall design. Consequently, our design tender responsibility is limited pending these findings.</p>
        <p><strong>Any emergency calculations</strong> that have been completed have been based on 0.5 lux for open areas and 1 lux for escape routes unless otherwise stated.</p>
        <p><strong>Industry standard design parameters</strong> have been used to carry out all calculations. Please advise if any of the information or results in the following pages are incorrect or not suitable for the end user's requirements. If an alternative specification can be provided, we can amend the design accordingly.</p>
        <p><strong>Please ensure that this lighting scheme complies with all customer and project requirements.</strong> If further details or calculations are required, please contact the Design Engineer named on the front cover.</p>
        <p><strong>Most luminaires are tested and warranted to operate in ambient temperatures up to 25°C.</strong> If the ambient temperature is above this it may cause issues with the standard operation of the luminaires. Installation in an area where this is exceeded may damage the fittings and invalidate the warranty.</p>
        <p><strong>Final quantities</strong> are to be confirmed by the end user prior to an order being placed and we will not be held responsible for any errors or omissions.</p>
        <p><strong>The nominal values</strong> shown within the attached are the result of precision calculations based upon precisely positioned luminaires in a fixed relationship to each other and to the specific area under examination. In practice the result values may vary due to tolerances on luminaires, luminaire positioning, installation properties and electrical supply.</p>
        <p>Whilst every effort will be made to adhere to any written or product specification provided it is always the responsibility of the customer to ensure that any design scheme or product supplied fully satisfies and complies with the end user's requirements.</p>
    </div>
    <div class="reference">Ref: {{ $project->reference_number }}</div>
    <footer>Tamlite Lighting · Stafford Park 12, Telford, Shropshire TF3 3BJ</footer>
    <div class="bottom-band"></div>
</section>
</body>
</html>
