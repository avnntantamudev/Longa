<script setup lang="ts">
import { usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';

/** Carteira do técnico: saldos, recarga, levantamentos e movimentos. */
interface Summary {
    available: number;
    pending: number;
    total_earned: number;
    commission_due: number;
    net_balance: number;
    min_balance: number;
    receiving_requests: boolean;
    pending_withdrawals: number;
}

interface Withdrawal {
    id: number;
    amount: number;
    destination: string;
    status: 'requested' | 'paid' | 'rejected';
    status_label: string;
    note: string | null;
    created_at: string;
}

interface Movement {
    id: number;
    bucket: 'available' | 'commission_due';
    type: string;
    label: string;
    amount: number;
    session_id: number | null;
    created_at: string;
}

const page = usePage();
const summary = ref<Summary | null>(null);
const movements = ref<Movement[]>([]);
const next = ref<string | null>(null);
const loadingMore = ref(false);
const withdrawals = ref<Withdrawal[]>([]);
const open = ref<'topup' | 'withdraw' | null>(null);
const topUp = reactive({ amount: null as number | null, method: 'multicaixa_express' as 'multicaixa_express' | 'reference' | 'card' });
const withdraw = reactive({ amount: null as number | null, destination: '' });
const busy = ref(false);
const error = ref('');
const notice = ref('');

async function submit(kind: 'topup' | 'withdraw'): Promise<void> {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        if (kind === 'topup') {
            await api.post('/wallet/top-ups', topUp, { idempotencyKey: newIdempotencyKey() });
            notice.value = 'Carteira recarregada.';
        } else {
            await api.post('/wallet/withdrawals', withdraw, { idempotencyKey: newIdempotencyKey() });
            notice.value = 'Levantamento pedido. O BISCA paga em até 2 dias úteis.';
        }
        open.value = null;
        await load();
    } catch (e) {
        error.value = e instanceof ApiError ? (Object.values(e.fieldErrors())[0] ?? e.message) : 'Sem ligação. Tente de novo.';
    } finally {
        busy.value = false;
    }
}

async function load() {
    const [s, m] = await Promise.all([
        api.get<{ data: Summary }>('/wallet'),
        api.get<{ data: Movement[]; meta: { next_cursor: string | null } }>('/wallet/movements'),
    ]);
    summary.value = s.data;
    movements.value = m.data;
    next.value = m.meta.next_cursor;
    withdrawals.value = (await api.get<{ data: Withdrawal[] }>('/wallet/withdrawals')).data;
}

async function more() {
    if (!next.value) return;
    loadingMore.value = true;
    try {
        const m = await api.get<{ data: Movement[]; meta: { next_cursor: string | null } }>(`/wallet/movements?cursor=${encodeURIComponent(next.value)}`);
        movements.value.push(...m.data);
        next.value = m.meta.next_cursor;
    } finally {
        loadingMore.value = false;
    }
}

usePrivateChannel(`user.${page.props.auth.user?.id ?? 0}`, { 'wallet.updated': () => load() });
onMounted(load);
</script>

<template>
    <Head title="Carteira" />

    <h1 class="text-display">Carteira</h1>

    <template v-if="summary">
        <section class="mt-4 rounded-sheet bg-navy-900 p-5 text-white">
            <p class="text-caption text-navy-200">Disponível</p>
            <p class="text-display" data-testid="available">{{ kwanza(summary.available) }}</p>
            <p class="mt-1 text-caption text-navy-200">Levantamentos disponíveis brevemente.</p>
            <Link href="/vip" class="mt-3 inline-block rounded-full bg-orange-500 px-4 py-1.5 text-caption font-semibold text-white">⭐ Técnico VIP</Link>
            <Link href="/carteira/ganhos" class="mt-3 ml-2 inline-block rounded-full bg-white/10 px-4 py-1.5 text-caption font-semibold text-white">Os meus ganhos</Link>
        </section>

        <div class="mt-3 grid grid-cols-2 gap-3">
            <div class="card">
                <p class="text-caption text-ink-muted">Pendente</p>
                <p class="font-semibold">{{ kwanza(summary.pending) }}</p>
                <p class="text-caption text-ink-muted">Serviços pagos por confirmar</p>
            </div>
            <div class="card">
                <p class="text-caption text-ink-muted">Total ganho</p>
                <p class="font-semibold">{{ kwanza(summary.total_earned) }}</p>
            </div>
        </div>
        <p v-if="!summary.receiving_requests" class="card mt-3 border-l-4 border-danger text-caption" role="alert">
            Não está a receber pedidos: deve {{ kwanza(summary.commission_due) }} em comissões. Recarregue a carteira para voltar a receber pedidos.
        </p>

        <div class="mt-3 grid grid-cols-2 gap-2">
            <button type="button" class="btn-primary" @click="open = open === 'topup' ? null : 'topup'">Recarregar</button>
            <button type="button" class="btn-outline" :disabled="summary.available <= 0" @click="open = open === 'withdraw' ? null : 'withdraw'">Levantar</button>
        </div>
        <p v-if="notice" class="mt-2 text-caption text-success" role="status">{{ notice }}</p>
        <p v-if="error" class="mt-2 text-caption text-danger" role="alert">{{ error }}</p>

        <form v-if="open === 'topup'" class="card mt-3 grid gap-2" @submit.prevent="submit('topup')">
            <input v-model.number="topUp.amount" class="input" type="number" min="1000" step="500" inputmode="numeric" placeholder="Valor (Kz)" aria-label="Valor da recarga (Kz)" />
            <div class="flex flex-wrap gap-2">
                <button type="button" class="chip" :class="{ 'chip-active': topUp.method === 'multicaixa_express' }" @click="topUp.method = 'multicaixa_express'">Multicaixa Express</button>
                <button type="button" class="chip" :class="{ 'chip-active': topUp.method === 'reference' }" @click="topUp.method = 'reference'">Referência</button>
                <button type="button" class="chip" :class="{ 'chip-active': topUp.method === 'card' }" @click="topUp.method = 'card'">Cartão</button>
            </div>
            <p class="text-caption text-ink-muted">As comissões em dívida são descontadas do valor recarregado.</p>
            <button type="submit" class="btn-primary w-full" :disabled="busy || !topUp.amount">{{ busy ? 'A processar…' : 'Recarregar' }}</button>
        </form>

        <form v-if="open === 'withdraw'" class="card mt-3 grid gap-2" @submit.prevent="submit('withdraw')">
            <input v-model.number="withdraw.amount" class="input" type="number" min="5000" step="500" inputmode="numeric" :placeholder="`Valor (até ${kwanza(summary.available)})`" aria-label="Valor do levantamento (Kz)" />
            <input v-model="withdraw.destination" class="input" maxlength="120" placeholder="IBAN ou número Multicaixa Express" aria-label="Destino do levantamento" />
            <p class="text-caption text-ink-muted">Mínimo 5.000 Kz. O valor fica retido até o BISCA fazer a transferência.</p>
            <button type="submit" class="btn-primary w-full" :disabled="busy || !withdraw.amount || withdraw.destination.length < 9">{{ busy ? 'A pedir…' : 'Pedir levantamento' }}</button>
        </form>

        <section v-if="withdrawals.length" class="card mt-3 grid gap-2">
            <h2 class="font-semibold">Levantamentos</h2>
            <p v-for="w in withdrawals" :key="w.id" class="flex justify-between text-caption">
                <span>{{ when(w.created_at) }} · {{ w.status_label }}<template v-if="w.note"> ({{ w.note }})</template></span>
                <strong>{{ kwanza(w.amount) }}</strong>
            </p>
        </section>

        <p v-if="summary.commission_due > 0" class="card mt-3 text-caption">
            Comissões de serviços pagos em mão por entregar: <strong>{{ kwanza(summary.commission_due) }}</strong>.
            São descontadas automaticamente dos próximos pagamentos pelo BISCA.
        </p>

        <h2 class="mt-6 text-title">Movimentos</h2>
        <p v-if="movements.length === 0" class="card mt-3 text-ink-muted">Ainda não há movimentos.</p>
        <ul v-else class="mt-3 grid gap-2">
            <li v-for="m in movements" :key="m.id" class="card flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ m.label }}</p>
                    <p class="text-caption text-ink-muted">
                        {{ when(m.created_at) }}
                        <Link v-if="m.session_id" :href="`/servicos/${m.session_id}`" class="underline">· ver serviço</Link>
                    </p>
                </div>
                <span class="shrink-0 font-semibold" :class="m.amount > 0 && m.bucket === 'available' ? 'text-success' : ''">
                    <!-- Na dívida, um lançamento negativo aumenta o que o técnico deve -->
                    <template v-if="m.bucket === 'commission_due'">Dívida {{ m.amount < 0 ? '+' : '−' }}{{ kwanza(Math.abs(m.amount)) }}</template>
                    <template v-else>{{ m.amount > 0 ? '+' : '−' }}{{ kwanza(Math.abs(m.amount)) }}</template>
                </span>
            </li>
        </ul>
        <button v-if="next" class="btn-outline mt-3 w-full" :disabled="loadingMore" @click="more">Ver mais</button>
    </template>

    <p v-else class="mt-6 text-ink-muted">A carregar…</p>
</template>
