<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0b1f3a; }
        h1 { font-size: 16px; margin: 0; }
        .period { color: #5b6b82; margin: 2px 0 14px; }
        h2 { font-size: 12px; margin: 16px 0 6px; color: #1f416f; border-bottom: 2px solid #ff7a00; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e3e8f0; text-align: left; }
        th { background: #eef3fa; }
        td.n { text-align: right; }
        .foot { margin-top: 18px; color: #5b6b82; font-size: 8px; }
    </style>
</head>
<body>
    <h1>Garimpo · {{ $title }}</h1>
    <p class="period">{{ $period }}</p>

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            <thead>
                <tr>@foreach ($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>@foreach ($row as $cell)<td class="{{ is_int($cell) || is_float($cell) ? 'n' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', '.') : $cell }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($section['headers']) }}">Sem dados no período.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <p class="foot">Gerado em {{ now()->format('d/m/Y H:i') }}. Valores em Kz.</p>
</body>
</html>
