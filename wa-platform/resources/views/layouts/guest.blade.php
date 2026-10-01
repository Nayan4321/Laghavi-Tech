<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'WhatsApp Team Platform')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-slate-100 text-slate-800">
    <div class="min-h-full flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-500 text-white text-2xl shadow">💬</div>
                <h1 class="mt-3 text-xl font-semibold">WhatsApp Team Platform</h1>
            </div>
            <div class="bg-white rounded-2xl shadow p-6">
                @if (session('status'))
                    <div class="mb-4 rounded-lg bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
            <p class="text-center text-xs text-slate-400 mt-6">Company sales numbers · monitored for quality</p>
        </div>
    </div>
</body>
</html>
