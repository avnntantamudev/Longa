<script setup lang="ts">
/**
 * Marca LONGA: livro aberto com um ponto (mesma geometria de scripts/generate-icons.py).
 * variant "light" para fundos escuros (texto branco), "dark" para fundos claros (texto marinho).
 */
withDefaults(defineProps<{ variant?: 'light' | 'dark'; size?: number; wordmark?: boolean }>(), {
    variant: 'light',
    size: 32,
    wordmark: true,
});
</script>

<template>
    <span class="inline-flex items-center gap-2" aria-label="LONGA">
        <svg :width="size" :height="size" viewBox="0 0 100 100" aria-hidden="true">
            <polygon points="14,36 47,44 47,84 14,76" fill="#FF7A00" />
            <polygon points="53,44 86,36 86,76 53,84" fill="#FF7A00" />
            <circle cx="50" cy="25" r="8.5" fill="#FF7A00" />
        </svg>
        <span
            v-if="wordmark"
            class="font-extrabold tracking-tight"
            :class="variant === 'light' ? 'text-white' : 'text-navy-900'"
            :style="{ fontSize: `${size * 0.75}px`, lineHeight: 1 }"
        >
            LONGA
        </span>
    </span>
</template>
