<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Foto de perfil ou, sem foto (ou se não carregar), as iniciais no círculo laranja do kit.
 * `src` deve ser a miniatura (128 px) em listas e a foto de 512 px em destaque.
 */
const props = withDefaults(defineProps<{ name?: string | null; src?: string | null; size?: number }>(), { name: null, src: null, size: 40 });

const failed = ref(false);
watch(
    () => props.src,
    () => (failed.value = false),
);

const initials = computed(() =>
    (props.name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || '?',
);
const style = computed(() => ({ width: `${props.size}px`, height: `${props.size}px`, fontSize: `${Math.max(12, Math.round(props.size * 0.36))}px` }));
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        :alt="name ? `Foto de ${name}` : 'Foto de perfil'"
        :style="style"
        class="shrink-0 rounded-full bg-navy-50 object-cover"
        loading="lazy"
        decoding="async"
        @error="failed = true"
    />
    <span v-else class="avatar-initials" :style="style" aria-hidden="true">{{ initials }}</span>
</template>
