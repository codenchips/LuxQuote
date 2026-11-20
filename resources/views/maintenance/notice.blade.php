<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LuxQuote maintenance</title>
    <style>
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 1.5rem; background: #101014; color: #fff; font-family: sans-serif; text-align: center; }
        main { max-width: 36rem; }
        p { color: #c5c7ce; line-height: 1.5; }
        dialog { width: min(28rem, calc(100% - 3rem)); padding: 2rem; border: 1px solid #3f3f46; border-radius: .75rem; background: #18181b; color: #fff; text-align: center; }
        dialog::backdrop { background: rgba(0, 0, 0, .65); }
        dialog h2 { margin-top: 0; }
        .button { display: inline-block; margin-top: .5rem; padding: .6rem 1.1rem; border: 0; border-radius: .5rem; background: #ff9800; color: #000; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button:hover { background: #ffb13b; }
        .button:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <h1>LuxQuote maintenance</h1>
        <p>{{ $active ? 'LuxQuote is undergoing scheduled maintenance. Please try again after the update is complete.' : 'The update has completed. You can sign in again.' }}</p>
        <a class="button" href="{{ route('filament.admin.auth.login') }}">Sign in</a>
    </main>
    <dialog id="maintenance-dialog" aria-labelledby="maintenance-dialog-title" aria-describedby="maintenance-dialog-description">
        <h2 id="maintenance-dialog-title">You have been signed out</h2>
        <p id="maintenance-dialog-description">LuxQuote signed you out for scheduled maintenance. You can sign in again once the update has finished.</p>
        <button class="button" type="button" onclick="document.getElementById('maintenance-dialog').close()">OK</button>
    </dialog>
    <script>document.getElementById('maintenance-dialog').showModal();</script>
</body>
</html>
