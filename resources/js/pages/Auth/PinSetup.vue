<script setup lang="ts">
import PinPad from '@/components/PinPad.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/** Criar (ou repor) o PIN de 6 dígitos: escrever e confirmar. */

defineProps<{ reset: boolean }>();

const step = ref<'first' | 'confirm'>('first');
const first = ref('');
const form = useForm({ pin: '', pin_confirmation: '' });
const localError = ref<string | null>(null);

function onFirst(pin: string) {
    localError.value = null;
    form.pin = pin;
    step.value = 'confirm';
}

function onConfirm(pin: string) {
    if (pin !== form.pin) {
        localError.value = 'Os dois PIN não coincidem. Comece de novo.';
        restart();
        return;
    }
    form.pin_confirmation = pin;
    form.post('/pin/criar', { onError: () => restart() });
}

function restart() {
    first.value = '';
    form.pin_confirmation = '';
    step.value = 'first';
}
</script>

<template>
    <Head title="Criar PIN" />

    <div class="text-center">
        <h1 class="text-title">{{ reset ? 'Crie um PIN novo' : 'Crie o seu PIN' }}</h1>
        <p class="mt-1 text-caption text-ink-muted">
            {{ step === 'first' ? 'Da próxima vez entra só com estes 6 dígitos, sem código SMS.' : 'Escreva o mesmo PIN outra vez.' }}
        </p>
    </div>

    <div class="mt-6">
        <PinPad v-if="step === 'first'" v-model="first" :error="localError ?? form.errors.pin" :disabled="form.processing" label="Novo PIN" @complete="onFirst" />
        <PinPad v-else v-model="form.pin_confirmation" :disabled="form.processing" label="Confirmar PIN" @complete="onConfirm" />
    </div>

    <p class="mt-6 text-center text-caption text-ink-muted">Evite datas de nascimento, números seguidos (123456) ou repetidos (111111). Não partilhe o PIN com ninguém.</p>
</template>
