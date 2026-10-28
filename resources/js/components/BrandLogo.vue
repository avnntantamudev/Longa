<script setup lang="ts">
import { computed } from 'vue';

/**
 * Marca BISCA: duas setas em "S" (laranja em cima, escura em baixo), logótipo oficial enviado a 2026-10-28.
 * Os mesmos caminhos geram os ícones da PWA em scripts/generate-icons.py.
 * variant "light" para fundos escuros (seta e texto brancos), "dark" para fundos claros (seta e texto escuros).
 */
const props = withDefaults(defineProps<{ variant?: 'light' | 'dark'; size?: number; wordmark?: boolean; tagline?: boolean }>(), {
    variant: 'light',
    size: 32,
    wordmark: true,
    tagline: false,
});

const ink = computed(() => (props.variant === 'light' ? '#FFFFFF' : '#222222'));
</script>

<template>
    <span class="inline-flex items-center gap-2" aria-label="BISCA">
        <svg :height="size" :width="size * 0.925" viewBox="530 105 555 600" aria-hidden="true">
            <path
                d="M615 450 C700 395 800 360 880 357 C990 355 1072 430 1072 515 C1072 620 950 698 815 698 C720 698 640 665 597 610 L552 657 L537 450 L727 520 L670 550 C720 600 780 612 830 612 C920 612 982 570 982 518 C982 460 920 420 840 420 C760 420 680 432 615 450 Z"
                :fill="ink"
            />
            <path
                d="M565 437 C540 330 590 200 720 140 C820 100 940 120 1015 202 L1057 155 L1077 360 L885 293 L940 262 C880 205 790 190 720 215 C630 245 575 330 565 437 Z"
                fill="#FF7300"
            />
        </svg>
        <span v-if="wordmark" class="flex flex-col" :style="{ color: ink }">
            <span class="font-extrabold tracking-[0.04em]" :style="{ fontSize: `${size * 0.62}px`, lineHeight: 1 }">BISCA</span>
            <span v-if="tagline" class="mt-1 tracking-[0.12em] opacity-80" :style="{ fontSize: `${Math.max(9, size * 0.2)}px`, lineHeight: 1 }">serviços • técnicos</span>
        </span>
    </span>
</template>
