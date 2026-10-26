<script setup lang="ts">
import PinPad from '@/components/PinPad.vue';
import { api, ApiError } from '@/lib/api';
import { when } from '@/lib/format';
import { onMounted, ref } from 'vue';

/** Perfil → Segurança: alterar o PIN e gerir os aparelhos onde entra só com o PIN. */
interface Device {
    id: string;
    name: string | null;
    last_used_at: string | null;
    current: boolean;
}

const devices = ref<Device[]>([]);
const step = ref<'idle' | 'current' | 'new' | 'confirm'>('idle');
const current = ref('');
const next = ref('');
const confirm = ref('');
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const busy = ref(false);

async function load() {
    devices.value = (await api.get<{ data: Device[] }>('/auth/devices')).data;
}

function start() {
    current.value = next.value = confirm.value = '';
    error.value = notice.value = null;
    step.value = 'current';
}

async function save() {
    if (confirm.value !== next.value) {
        error.value = 'Os dois PIN não coincidem.';
        next.value = confirm.value = '';
        step.value = 'new';
        return;
    }
    busy.value = true;
    try {
        await api.post('/auth/pin/change', { current_pin: current.value, pin: next.value, pin_confirmation: confirm.value });
        notice.value = 'PIN alterado. Os outros aparelhos saíram da conta.';
        step.value = 'idle';
        await load();
    } catch (e) {
        const fields = e instanceof ApiError ? e.fieldErrors() : {};
        error.value = fields.pin ?? fields.current_pin ?? (e instanceof ApiError ? e.message : 'Sem ligação.');
        current.value = next.value = confirm.value = '';
        step.value = fields.pin ? 'new' : 'current';
    } finally {
        busy.value = false;
    }
}

async function remove(device: Device) {
    await api.delete(`/auth/devices/${device.id}`);
    await load();
}

onMounted(load);
</script>

<template>
    <section class="card mt-4 grid gap-3">
        <h2 class="font-semibold">Segurança</h2>
        <p v-if="notice" class="rounded-control bg-success/10 p-3 text-caption font-semibold text-success">{{ notice }}</p>

        <button v-if="step === 'idle'" type="button" class="btn-outline w-full" @click="start">Alterar PIN</button>
        <div v-else class="grid gap-3">
            <p class="text-center text-caption font-semibold">
                {{ step === 'current' ? 'PIN atual' : step === 'new' ? 'PIN novo' : 'Confirme o PIN novo' }}
            </p>
            <PinPad v-if="step === 'current'" v-model="current" :error="error" @complete="step = 'new'; error = null" />
            <PinPad v-else-if="step === 'new'" v-model="next" :error="error" @complete="step = 'confirm'; error = null" />
            <PinPad v-else v-model="confirm" :disabled="busy" @complete="save" />
            <button type="button" class="text-caption font-semibold text-ink-muted" @click="step = 'idle'">Cancelar</button>
        </div>

        <h3 class="mt-2 text-caption font-semibold">Aparelhos que entram só com o PIN</h3>
        <ul class="grid gap-2 text-caption">
            <li v-for="d in devices" :key="d.id" class="flex items-center justify-between gap-2 rounded-control bg-navy-50 px-3 py-2">
                <span class="min-w-0">
                    <span class="font-semibold">{{ d.name ?? 'Aparelho' }}</span>
                    <span v-if="d.current" class="badge badge-success ml-1">Este</span>
                    <span v-if="d.last_used_at" class="block text-ink-muted">Último uso: {{ when(d.last_used_at) }}</span>
                </span>
                <button v-if="!d.current" type="button" class="shrink-0 font-semibold text-danger" @click="remove(d)">Remover</button>
            </li>
        </ul>
    </section>
</template>
