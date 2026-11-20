<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Database import</title></head>
<body style="font-family:sans-serif;background:#101014;color:white;padding:2rem">
    <h1>{{ $success ? 'Database import complete' : 'Database import failed' }}</h1>
    <p>{{ $message }}</p>
    @if ($success)<a style="color:#ffd046" href="{{ route('filament.admin.auth.login') }}">Sign in</a>@else<a style="color:#ffd046" href="{{ route('filament.admin.pages.tools') }}">Return to Tools</a>@endif
</body>
</html>
