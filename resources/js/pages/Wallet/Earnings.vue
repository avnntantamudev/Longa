<script setup lang="ts">
import { api } from '@/lib/api';
import { kwanza } from '@/lib/format';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';

/** Relatório de ganhos do formador por mês, com PDF e Excel. */
interface Month {
    month: number;
    lessons: number;
    cash_lessons: number;
    credited: number;
    commissions: number;
    cash_received: number;
}
interface Report {
    year: number;
    months: Month[];
    totals: Omit<Month, 'month'>;
}

const NAMES = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const currentYear = new Date().getFullYear();
const years = Array.from({ length: Math.max(1, currentYear - 2025) }, (_, i) => currentYear - i);
const year = ref(currentYear);
const report = ref<Report | null>(null);

async function load() {
    report.value = (await api.get<{ data: Report }>(`/me/reports/earnings?year=${year.value}`)).data;
}

watch(year, load);
onMounted(load);
</script>

<template>
    <Head title="Os meus ganhos" />

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-display">Os meus ganhos</h1>
        <select v-model.number="year" class="input w-28" aria-label="Ano">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
    </div>

    <template v-if="report">
        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="card">
                <p class="text-caption text-ink-muted">Aulas dadas</p>
                <p class="text-title">{{ report.totals.lessons }}</p>
                <p class="text-caption text-ink-muted">{{ report.totals.cash_lessons }} pagas em mão</p>
            </div>
            <div class="card">
                <p class="text-caption text-ink-muted">Creditado na carteira</p>
                <p class="text-title">{{ kwanza(report.totals.credited) }}</p>
            </div>
            <div class="card">
                <p class="text-caption text-ink-muted">Recebido em mão</p>
                <p class="font-semibold">{{ kwanza(report.totals.cash_received) }}</p>
            </div>
            <div class="card">
                <p class="text-caption text-ink-muted">Comissões do BISCA</p>
                <p class="font-semibold">{{ kwanza(report.totals.commissions) }}</p>
            </div>
        </div>

        <div class="card mt-4 overflow-x-auto p-0">
            <table class="w-full text-caption">
                <thead class="bg-navy-50 text-left">
                    <tr>
                        <th class="px-3 py-2">Mês</th>
                        <th class="px-3 py-2 text-right">Aulas</th>
                        <th class="px-3 py-2 text-right">Carteira</th>
                        <th class="px-3 py-2 text-right">Em mão</th>
                        <th class="px-3 py-2 text-right">Comissões</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in report.months" :key="m.month" class="border-t border-border" :class="{ 'text-ink-muted': m.lessons === 0 && m.credited === 0 }">
                        <td class="px-3 py-2">{{ NAMES[m.month] }}</td>
                        <td class="px-3 py-2 text-right">{{ m.lessons }}</td>
                        <td class="px-3 py-2 text-right">{{ kwanza(m.credited) }}</td>
                        <td class="px-3 py-2 text-right">{{ kwanza(m.cash_received) }}</td>
                        <td class="px-3 py-2 text-right">{{ kwanza(m.commissions) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3">
            <a :href="`/api/v1/me/reports/earnings/export?year=${year}&format=pdf`" class="btn-secondary">Descarregar PDF</a>
            <a :href="`/api/v1/me/reports/earnings/export?year=${year}&format=csv`" class="btn-outline">Excel (CSV)</a>
        </div>
        <Link href="/carteira" class="mt-4 block text-center text-caption font-semibold">Voltar à carteira</Link>
    </template>
    <p v-else class="mt-6 text-ink-muted">A carregar…</p>
</template>
