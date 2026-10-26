<script setup lang="ts">
import { usePrivateChannel } from '@/composables/useRealtime';
import { api } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

/** Carteira do formador (só leitura; levantamentos chegam numa fase seguinte). */
interface Summary {
    available: number;
    pending: number;
    total_earned: number;
    commission_due: number;
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

async function load() {
    const [s, m] = await Promise.all([
        api.get<{ data: Summary }>('/wallet'),
        api.get<{ data: Movement[]; meta: { next_cursor: string | null } }>('/wallet/movements'),
    ]);
    summary.value = s.data;
    movements.value = m.data;
    next.value = m.meta.next_cursor;
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
        </section>

        <div class="mt-3 grid grid-cols-2 gap-3">
            <div class="card">
                <p class="text-caption text-ink-muted">Pendente</p>
                <p class="font-semibold">{{ kwanza(summary.pending) }}</p>
                <p class="text-caption text-ink-muted">Aulas pagas por confirmar</p>
            </div>
            <div class="card">
                <p class="text-caption text-ink-muted">Total ganho</p>
                <p class="font-semibold">{{ kwanza(summary.total_earned) }}</p>
            </div>
        </div>
        <p v-if="summary.commission_due > 0" class="card mt-3 text-caption">
            Comissões de aulas pagas em mão por entregar: <strong>{{ kwanza(summary.commission_due) }}</strong>.
            São descontadas automaticamente dos próximos pagamentos pela LONGA.
        </p>

        <h2 class="mt-6 text-title">Movimentos</h2>
        <p v-if="movements.length === 0" class="card mt-3 text-ink-muted">Ainda não há movimentos.</p>
        <ul v-else class="mt-3 grid gap-2">
            <li v-for="m in movements" :key="m.id" class="card flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ m.label }}</p>
                    <p class="text-caption text-ink-muted">
                        {{ when(m.created_at) }}
                        <Link v-if="m.session_id" :href="`/aulas/${m.session_id}`" class="underline">· ver aula</Link>
                    </p>
                </div>
                <span class="shrink-0 font-semibold" :class="m.amount > 0 && m.bucket === 'available' ? 'text-success' : ''">
                    <!-- Na dívida, um lançamento negativo aumenta o que o formador deve -->
                    <template v-if="m.bucket === 'commission_due'">Dívida {{ m.amount < 0 ? '+' : '−' }}{{ kwanza(Math.abs(m.amount)) }}</template>
                    <template v-else>{{ m.amount > 0 ? '+' : '−' }}{{ kwanza(Math.abs(m.amount)) }}</template>
                </span>
            </li>
        </ul>
        <button v-if="next" class="btn-outline mt-3 w-full" :disabled="loadingMore" @click="more">Ver mais</button>
    </template>

    <p v-else class="mt-6 text-ink-muted">A carregar…</p>
</template>
