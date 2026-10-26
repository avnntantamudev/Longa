<x-filament-panels::page>
    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end">
        <div style="display:flex; gap:4px">
            <x-filament::button :color="$tab === 'financial' ? 'primary' : 'gray'" wire:click="$set('tab', 'financial')">Financeiro</x-filament::button>
            <x-filament::button :color="$tab === 'operations' ? 'primary' : 'gray'" wire:click="$set('tab', 'operations')">Operação</x-filament::button>
        </div>
        <label style="display:grid; gap:4px; font-size:12px">
            De
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
        </label>
        <label style="display:grid; gap:4px; font-size:12px">
            Até
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
        </label>
    </div>

    <p style="font-size:13px; opacity:.7">{{ $this->periodLabel() }} · valores em Kz</p>

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
                            <tr><td colspan="{{ count($section['headers']) }}" style="padding:6px 8px; opacity:.6">Sem dados no período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
