<script setup lang="ts">
/**
 * Marca BISCA (provisória até chegar o logótipo oficial): pino de localização laranja com um "B" branco.
 * A mesma geometria gera os ícones da PWA em scripts/generate-icons.py.
 * variant "light" para fundos escuros (texto branco), "dark" para fundos claros (texto escuro).
 */
withDefaults(defineProps<{ variant?: 'light' | 'dark'; size?: number; wordmark?: boolean; tagline?: boolean }>(), {
    variant: 'light',
    size: 32,
    wordmark: true,
    tagline: false,
});
</script>

<template>
    <span class="inline-flex items-center gap-2" aria-label="BISCA">
        <svg :height="size" :width="size * 0.786" viewBox="0 0 78.6 100" aria-hidden="true">
            <path d="M39.3 100 L9.3 64.7 A39.3 39.3 0 1 1 69.3 64.7 Z" fill="#ED731D" />
            <!-- "B": dois "D" brancos com um furo laranja cada -->
            <path d="M27 22 H41 A9 9 0 0 1 41 40 H27 Z M27 38 H43 A9.5 9.5 0 0 1 43 57 H27 Z" fill="#FFFFFF" />
            <path d="M33.5 28 H41 A3 3 0 0 1 41 34 H33.5 Z M33.5 44.5 H43 A3 3 0 0 1 43 50.5 H33.5 Z" fill="#ED731D" />
        </svg>
        <span v-if="wordmark" class="flex flex-col" :class="variant === 'light' ? 'text-white' : 'text-[#1C1C1C]'">
            <span class="font-bold tracking-[0.06em]" :style="{ fontSize: `${size * 0.62}px`, lineHeight: 1 }">BISCA</span>
            <span v-if="tagline" class="mt-1 tracking-[0.12em] opacity-80" :style="{ fontSize: `${Math.max(9, size * 0.2)}px`, lineHeight: 1 }">serviços • técnicos</span>
        </span>
    </span>
</template>
