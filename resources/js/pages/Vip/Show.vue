<script setup lang="ts">
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

/** Técnico VIP: estado, vantagens e compra/renovação (sem reembolso a meio do período). */
interface Vip {
    is_vip: boolean;
    vip_until: string | null;
    price: number;
    duration_days: number;
    commission_percent: number;
    standard_commission_percent: number;
    history: { id: number; source: string; amount: number; starts_at: string; ends_at: string }[];
}

const vip = ref<Vip | null>(null);
const method = ref<'multicaixa_express' | 'reference'>('multicaixa_express');
const accept = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
let key: string | null = null;

async function load() {
    vip.value = (await api.get<{ data: Vip }>('/me/vip')).data;
}

async function buy() {
    error.value = '';
    if (!accept.value) {
        error.value = 'Confirme que leu as condições.';
        return;
    }
    busy.value = true;
    key ??= newIdempotencyKey(); // a mesma chave em repetições por falha de rede
    try {
        await api.post('/me/vip/purchase', { method: method.value, accept_terms: true }, { idempotencyKey: key });
        key = null;
        notice.value = 'Pagamento confirmado. Já é técnico VIP!';
        await load();
    } catch (e) {
        if (e instanceof ApiError) {
            key = null;
            error.value = Object.values(e.fieldErrors())[0] ?? e.message;
        } else {
            error.value = 'Sem ligação. Tente de novo: não será cobrado duas vezes.';
        }
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head title="Técnico VIP" />

    <h1 class="text-display">Técnico VIP</h1>

    <template v-if="vip">
        <section class="mt-4 rounded-sheet bg-navy-900 p-5 text-white">
            <p class="text-caption text-orange-400 font-semibold">{{ vip.is_vip ? 'É VIP' : 'Ainda não é VIP' }}</p>
            <p v-if="vip.is_vip && vip.vip_until" class="text-title">Até {{ when(vip.vip_until) }}</p>
            <p class="mt-1 text-display">{{ kwanza(vip.price) }} <span class="text-caption text-navy-200">/ {{ vip.duration_days }} dias</span></p>
        </section>

        <section class="card mt-4">
            <h2 class="font-semibold">Vantagens</h2>
            <ul class="mt-2 grid gap-2 text-caption">
                <li>⭐ <strong>Pedidos de outro dia em toda a província</strong>, não só a 5 km.</li>
                <li>⚡ <strong>Prioridade</strong>: recebe os pedidos TRIM TRIM antes dos outros técnicos.</li>
                <li>💰 <strong>Comissão de {{ vip.commission_percent }}%</strong> em vez de {{ vip.standard_commission_percent }}% nos serviços aceites enquanto for VIP.</li>
                <li>🏅 <strong>Selo VIP</strong> visível aos clientes.</li>
            </ul>
        </section>

        <section class="card mt-4 grid gap-3" aria-live="polite">
            <h2 class="font-semibold">{{ vip.is_vip ? 'Renovar' : 'Tornar-me VIP' }}</h2>
            <p v-if="vip.is_vip" class="text-caption text-ink-muted">Renovar agora soma {{ vip.duration_days }} dias ao fim do seu VIP atual: não perde dias.</p>
            <div class="flex gap-2">
                <button type="button" class="chip" :class="{ 'chip-active': method === 'multicaixa_express' }" @click="method = 'multicaixa_express'">Multicaixa Express</button>
                <button type="button" class="chip" :class="{ 'chip-active': method === 'reference' }" @click="method = 'reference'">Referência</button>
            </div>
            <label class="flex items-start gap-3 text-caption">
                <input v-model="accept" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
                <span>Li e aceito: a subscrição dura {{ vip.duration_days }} dias e <strong>não é reembolsável</strong> a meio do período.</span>
            </label>
            <p v-if="notice" class="rounded-control bg-success/10 p-3 text-caption font-semibold text-success">{{ notice }}</p>
            <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
            <button class="btn-primary w-full" :disabled="busy" @click="buy">{{ busy ? 'A processar…' : `Pagar ${kwanza(vip.price)}` }}</button>
        </section>

        <section v-if="vip.history.length" class="card mt-4">
            <h2 class="font-semibold">Histórico</h2>
            <ul class="mt-2 grid gap-1 text-caption">
                <li v-for="h in vip.history" :key="h.id" class="flex justify-between">
                    <span>{{ when(h.starts_at) }} → {{ when(h.ends_at) }}</span>
                    <span>{{ h.source === 'courtesy' ? 'Cortesia' : kwanza(h.amount) }}</span>
                </li>
            </ul>
        </section>
    </template>
    <p v-else class="mt-6 text-ink-muted">A carregar…</p>
</template>
