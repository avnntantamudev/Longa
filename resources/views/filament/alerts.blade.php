<x-filament-panels::page>
    <div wire:poll.30s style="display:grid; gap:24px">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px">
            @foreach ($this->counts() as $label => $count)
                <x-filament::section>
                    <p style="font-size:13px; opacity:.7">{{ $label }}</p>
                    <p style="font-size:24px; font-weight:600">{{ $count }}</p>
                </x-filament::section>
            @endforeach
        </div>

        @foreach ($this->sections() as $section)
            <x-filament::section :heading="$section['title']">
                <div style="overflow-x:auto">
                    <table style="width:100%; border-collapse:collapse; font-size:13px">
                        <thead>
                            <tr>
                                @foreach ($section['headers'] as $header)
                                    <th style="text-align:left; padding:6px 8px; border-bottom:1px solid rgba(127,127,127,.3)">{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($section['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td style="padding:6px 8px; border-bottom:1px solid rgba(127,127,127,.15); {{ is_int($cell) ? 'text-align:right; font-variant-numeric:tabular-nums' : '' }}">
                                            {{ is_int($cell) ? number_format($cell, 0, ',', '.') : $cell }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($section['headers']) }}" style="padding:6px 8px; opacity:.6">{{ $section['empty'] }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
