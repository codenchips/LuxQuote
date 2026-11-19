@foreach (['Regular' => 400, 'SemiBold' => 600] as $brandWeight => $brandFontWeight)
@font-face {
    font-family: TamliteBrand;
    src: url(data:font/ttf;base64,{{ base64_encode(file_get_contents(public_path('fonts/chivo/Chivo-'.$brandWeight.'.ttf'))) }}) format('truetype');
    font-weight: {{ $brandFontWeight }};
}
@endforeach
.pdf-brand-panel { position: absolute; top: 0; right: 0; width: 115mm; height: 30mm; color: #fff; font-family: TamliteBrand, Arial, sans-serif; }
.pdf-brand-panel-background { position: absolute; inset: 0; background: #1a2943; clip-path: polygon(0 0,100% 0,100% 100%,9% 100%); }
.pdf-brand-panel-logo { position: absolute; width: 38mm; height: 28mm; object-fit: contain; right: 60mm; top: .5mm; }
.pdf-brand-panel-contact { position: absolute; right: 10mm; top: 6mm; font-size: 9pt; line-height: 1.45; text-align: right; }
.pdf-brand-panel-contact strong { font-size: 13pt; font-weight: 600; }
