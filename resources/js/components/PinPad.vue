<script setup lang="ts">
import { computed } from 'vue';

/**
 * Teclado de PIN de 6 dígitos (botões grandes para o telemóvel; também aceita o teclado do computador).
 * Emite `complete` quando os 6 dígitos estão preenchidos.
 */
const model = defineModel<string>({ required: true });
const props = withDefaults(defineProps<{ error?: string | null; disabled?: boolean; label?: string }>(), { error: null, disabled: false, label: 'PIN' });
const emit = defineEmits<{ complete: [pin: string] }>();

const dots = computed(() => Array.from({ length: 6 }, (_, i) => i < model.value.length));

function press(digit: string) {
    if (props.disabled || model.value.length >= 6) return;
    model.value += digit;
    if (model.value.length === 6) emit('complete', model.value);
}

function back() {
    if (!props.disabled) model.value = model.value.slice(0, -1);
}

function onKey(event: KeyboardEvent) {
    if (/^\d$/.test(event.key)) press(event.key);
    else if (event.key === 'Backspace') back();
}
</script>

<template>
    <div class="grid justify-items-center gap-5" tabindex="0" :aria-label="label" @keydown="onKey">
        <div class="flex gap-3" role="status" :aria-label="`${model.length} de 6 dígitos`">
            <span v-for="(filled, i) in dots" :key="i" class="size-4 rounded-full border-2 transition-colors" :class="filled ? 'border-orange-500 bg-orange-500' : 'border-navy-200'" />
        </div>
        <p v-if="error" class="text-center text-caption font-semibold text-danger" role="alert">{{ error }}</p>
        <div class="grid w-full max-w-72 grid-cols-3 gap-3">
            <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" type="button" class="h-16 rounded-full bg-navy-50 text-title font-semibold active:bg-navy-100 disabled:opacity-50" :disabled="disabled" @click="press(d)">{{ d }}</button>
            <span />
            <button type="button" class="h-16 rounded-full bg-navy-50 text-title font-semibold active:bg-navy-100 disabled:opacity-50" :disabled="disabled" @click="press('0')">0</button>
            <button type="button" class="h-16 rounded-full text-caption font-semibold text-ink-muted" :disabled="disabled" aria-label="Apagar" @click="back">⌫</button>
        </div>
    </div>
</template>
