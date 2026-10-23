<script setup lang="ts">
/**
 * Ícones de traço (24x24) usados na navegação e estados. Herdam a cor do texto (currentColor).
 */
export type IconName = 'home' | 'search' | 'calendar' | 'wallet' | 'user' | 'wifi-off' | 'refresh' | 'download' | 'close' | 'share';

defineProps<{ name: IconName; size?: number }>();

const paths: Record<IconName, string[]> = {
    home: ['M3 10.5 12 3l9 7.5', 'M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5'],
    search: ['M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Z', 'm20 20-3.5-3.5'],
    calendar: ['M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6Z', 'M4 10h16', 'M8 2v4', 'M16 2v4'],
    wallet: ['M3 7a2 2 0 0 1 2-2h13v4', 'M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2Z', 'M16 14.5h.01'],
    user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 21a8 8 0 0 1 16 0'],
    'wifi-off': ['m2 2 20 20', 'M8.5 16.5a5 5 0 0 1 7 0', 'M5 12.5a10 10 0 0 1 5.2-2.8', 'M19 12.5a10 10 0 0 0-2.4-1.7', 'M12 20h.01'],
    refresh: ['M20 11a8 8 0 0 0-14.9-3.9L4 8.5', 'M4 4v4.5h4.5', 'M4 13a8 8 0 0 0 14.9 3.9l1.1-1.4', 'M20 20v-4.5h-4.5'],
    download: ['M12 4v11', 'm7 10 5 5 5-5', 'M5 20h14'],
    close: ['M6 6l12 12', 'M18 6 6 18'],
    share: ['M12 3v12', 'm8 7 4-4 4 4', 'M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7'],
};
</script>

<template>
    <svg
        :width="size ?? 24"
        :height="size ?? 24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path v-for="(d, i) in paths[name]" :key="i" :d="d" />
    </svg>
</template>
