<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · KlokOne</title>
    <meta name="robots" content="noindex">
    <style>
        :root {
            --paper: #0c0b13;
            --surface: #15141f;
            --surface-2: #1b1927;
            --ink: #eef2f6;
            --ink-2: #b3bcc8;
            --ink-3: #87919e;
            --line: #27243a;
            --brand: #a07fbd;
            --brand-ink: #c9afe6;
        }
        * { box-sizing: border-box; }
        html { background: radial-gradient(900px 480px at 12% -8%, rgb(160 127 189 / 0.12), transparent 70%), var(--paper); color: var(--ink); }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; font: 15.5px/1.5 'Onest', 'Anek Bangla', ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .card { width: 100%; max-width: 440px; padding: 28px 24px; border: 1px solid var(--line); border-radius: 18px; background: linear-gradient(180deg, #1a1828 0%, #14131e 100%); box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.06), 0 16px 40px -16px rgb(0 0 0 / 0.7); }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; letter-spacing: -0.01em; }
        .brand svg { width: 28px; height: 28px; border-radius: 8px; }
        .code { margin: 22px 0 4px; font-size: 12.5px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--brand-ink); display: flex; align-items: center; gap: 8px; }
        .code::before { content: ''; width: 20px; height: 3px; border-radius: 99px; background: var(--brand); }
        h1 { margin: 0; font-size: 24px; line-height: 1.25; letter-spacing: -0.02em; }
        p { margin: 10px 0 0; color: var(--ink-2); }
        .row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        a.btn { display: inline-flex; align-items: center; height: 42px; padding: 0 18px; border-radius: 11px; font-weight: 600; text-decoration: none; color: var(--ink); border: 1px solid var(--line); background: var(--surface-2); }
        a.btn.main { color: #fff; border-color: transparent; background: linear-gradient(135deg, #8d6bb3 0%, #5a3b82 100%); box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.18), 0 6px 16px -6px rgb(126 92 155 / 0.6); }
        .foot { margin-top: 18px; font-size: 13px; color: var(--ink-3); }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="16" fill="#1b302c"/><path d="M20 16v32M20 34l14-18M26 27l12 21" fill="none" stroke="#c2a3df" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/><path d="M42 40h6M42 32h6M44 24h4" stroke="#d5e2de" stroke-width="4" stroke-linecap="round"/></svg>
            KlokOne
        </div>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('body')</p>
        <div class="row">
            <a class="btn main" href="{{ url('/dashboard') }}">ডেস্কে যান</a>
            <a class="btn" href="javascript:history.back()">ফিরে যান</a>
        </div>
        <p class="foot">@yield('foot', 'কিছু ভুল মনে হলে Owner-কে বলুন।')</p>
    </main>
</body>
</html>
