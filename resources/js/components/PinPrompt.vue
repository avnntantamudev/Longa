<script setup lang="ts">
import PinPad from '@/components/PinPad.vue';
import { ApiError, rawRequest } from '@/lib/api';
import { pinPrompt } from '@/lib/pinPrompt';
import { ref, watch } from 'vue';

/** Teclado de PIN em janela, para confirmar pagamentos (PIN válido 5 minutos). */
const pin = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

watch(
    () => pinPrompt.open,
    (open) => {
        if (open) {
            pin.value = '';
            error.value = null;
        }
    },
);

async function submit(value: string) {
    busy.value = true;
    error.value = null;
    try {
        await rawRequest('POST', '/auth/pin/verify', { pin: value });
        pinPrompt.resolve?.();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
        pin.value = '';
    } finally {
        busy.value = false;
    }
}

function cancel() {
    pinPrompt.reject?.(new ApiError(423, 'pin_cancelled', 'Operação cancelada.'));
}
</script>

<template>
    <div v-if="pinPrompt.open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 md:items-center" role="dialog" aria-modal="true" aria-labelledby="pin-prompt-title">
        <div class="w-full max-w-sm rounded-sheet bg-surface p-6 shadow-nav">
            <h2 id="pin-prompt-title" class="text-center text-title">Confirme com o seu PIN</h2>
            <p class="mt-1 text-center text-caption text-ink-muted">Por segurança, pedimos o PIN antes de pagar.</p>
            <div class="mt-5">
                <PinPad v-model="pin" :error="error" :disabled="busy" @complete="submit" />
            </div>
            <button type="button" class="mt-4 w-full text-center text-caption font-semibold text-ink-muted" @click="cancel">Cancelar</button>
        </div>
    </div>
</template>
