<script setup lang="ts">
/**
 * Rótulo + campo + ajuda + erro. O campo vem no slot (input, select, textarea...).
 */
defineProps<{ label: string; error?: string | null; hint?: string; for?: string }>();
</script>

<template>
    <div class="grid gap-1.5">
        <label :for="$props.for" class="text-caption font-semibold text-ink">{{ label }}</label>
        <slot />
        <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
        <p v-else-if="hint" class="text-caption text-ink-muted">{{ hint }}</p>
    </div>
</template>
