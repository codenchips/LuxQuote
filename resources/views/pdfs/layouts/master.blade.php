<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Document')</title>
    <style>
        /* ── Page setup ──────────────────────────────────────────────────── */
        @page {
            size: A4 portrait;
            margin: 14mm 14mm 22mm 14mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8pt;
            color: #111827;
            background: #ffffff;
            line-height: 1.4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Fixed footer – rendered on every printed page ───────────────── */
        .pdf-footer {
            position: fixed;
            bottom: -18mm;
            left: 0;
            right: 0;
            height: 16mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 0.75pt solid #d1d5db;
            padding: 3mm 0 0 0;
            font-size: 7pt;
            color: #6b7280;
        }

        .pdf-footer .footer-right {
            text-align: right;
        }

        /* CSS counters for page numbers */
        .page-num::after  { content: counter(page); }
        .page-total::after { content: counter(pages); }

        @yield('styles')
    </style>
</head>
<body>

    {{-- Footer stamped on every page --}}
    <div class="pdf-footer">
        <span>@yield('footer-left')</span>
        <span class="footer-right">
            Page <span class="page-num"></span> of <span class="page-total"></span>
        </span>
    </div>

    @yield('content')

</body>
</html>
