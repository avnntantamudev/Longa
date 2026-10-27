<script setup lang="ts">
/**
 * Marca Garimpo: pino de localização laranja com um "G" (resources/brand/garimpo-logo.jpg).
 * A mesma geometria gera os ícones da PWA em scripts/generate-icons.py.
 * variant "light" para fundos escuros (texto branco), "dark" para fundos claros (texto escuro).
 */
withDefaults(defineProps<{ variant?: 'light' | 'dark'; size?: number; wordmark?: boolean; tagline?: boolean }>(), {
    variant: 'light',
    size: 32,
    wordmark: true,
    tagline: false,
});

const uid = `g${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <span class="inline-flex items-center gap-2" aria-label="Garimpo">
        <svg :height="size" :width="size * 0.786" viewBox="0 0 78.6 100" aria-hidden="true">
            <defs>
                <mask :id="uid">
                    <rect x="0" y="0" width="78.6" height="100" fill="white" />
                    <!-- furo do G e corte em diagonal (abertura do G) -->
                    <circle cx="39.3" cy="39.3" r="21.5" fill="black" />
                    <polygon points="51.3,34.6 80,34.6 80,6.1" fill="black" />
                </mask>
            </defs>
            <path d="M39.3 100 L9.3 64.7 A39.3 39.3 0 1 1 69.3 64.7 Z" fill="#ED731D" :mask="`url(#${uid})`" />
            <!-- barra do G (ponta redonda à esquerda) -->
            <circle cx="37.5" cy="40.7" r="6.1" fill="#ED731D" />
            <rect x="37.5" y="34.6" width="41.1" height="12.2" fill="#ED731D" />
        </svg>
        <span v-if="wordmark" class="flex flex-col" :class="variant === 'light' ? 'text-white' : 'text-[#1C1C1C]'">
            <span class="font-bold tracking-[0.06em]" :style="{ fontSize: `${size * 0.62}px`, lineHeight: 1 }">GARIMPO</span>
            <span v-if="tagline" class="mt-1 tracking-[0.12em] opacity-80" :style="{ fontSize: `${Math.max(9, size * 0.2)}px`, lineHeight: 1 }">marketplace • formadores</span>
        </span>
    </span>
</template>
