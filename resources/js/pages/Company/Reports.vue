<script setup lang="ts">
import { api } from '@/lib/api';
import { kwanza } from '@/lib/format';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

/** Relatório RH: serviços dos colaboradores que autorizaram a empresa. */
interface Company {
    id: number;
    name: string;
}
interface Report {
    totals: { employees: number; consented: number; lessons: number; hours: number; cost: number };
    by_department: { department: string; employees: number; lessons: number; hours: number; cost: number }[];
    by_employee: { name: string; department: string; lessons: number; hours: number; cost: number }[];
}

const companies = ref<Company[]>([]);
const companyId = ref<number | null>(null);
const today = new Date();
const from = ref(new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10));
const to = ref(today.toISOString().slice(0, 10));
const report = ref<Report | null>(null);
const query = computed(() => `from=${from.value}&to=${to.value}`);
const hours = (h: number) => `${h.toLocaleString('pt-AO', { maximumFractionDigits: 1 })} h`;

async function load() {
    if (!companyId.value) return;
    report.value = (await api.get<{ data: Report }>(`/companies/${companyId.value}/reports/services?${query.value}`)).data;
}

watch([companyId, from, to], load);
onMounted(async () => {
    companies.value = (await api.get<{ data: Company[] }>('/companies')).data;
    companyId.value = companies.value[0]?.id ?? null;
});
</script>

<template>
    <Head title="Serviços da equipa" />

    <h1 class="text-display">Serviços da equipa</h1>
    <p class="mt-1 text-caption text-ink-muted">Só aparecem colaboradores que autorizaram a empresa na app.</p>

    <p v-if="companies.length === 0" class="card mt-4 text-ink-muted">Ainda não gere nenhuma empresa.</p>

    <template v-else>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <select v-if="companies.length > 1" v-model.number="companyId" class="input" aria-label="Empresa">
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <input v-model="from" type="date" class="input" aria-label="De" />
            <input v-model="to" type="date" class="input" aria-label="Até" />
        </div>

        <template v-if="report">
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="card"><p class="text-caption text-ink-muted">Serviços</p><p class="text-title">{{ report.totals.lessons }}</p></div>
                <div class="card"><p class="text-caption text-ink-muted">Horas</p><p class="text-title">{{ hours(report.totals.hours) }}</p></div>
                <div class="card"><p class="text-caption text-ink-muted">Custo</p><p class="text-title">{{ kwanza(report.totals.cost) }}</p></div>
                <div class="card"><p class="text-caption text-ink-muted">Autorizaram</p><p class="text-title">{{ report.totals.consented }}/{{ report.totals.employees }}</p></div>
            </div>

            <h2 class="mt-6 text-title">Por departamento</h2>
            <div class="card mt-2 overflow-x-auto p-0">
                <table class="w-full text-caption">
                    <thead class="bg-navy-50 text-left"><tr><th class="px-3 py-2">Departamento</th><th class="px-3 py-2 text-right">Serviços</th><th class="px-3 py-2 text-right">Horas</th><th class="px-3 py-2 text-right">Custo</th></tr></thead>
                    <tbody>
                        <tr v-for="d in report.by_department" :key="d.department" class="border-t border-border">
                            <td class="px-3 py-2">{{ d.department }}</td><td class="px-3 py-2 text-right">{{ d.lessons }}</td><td class="px-3 py-2 text-right">{{ hours(d.hours) }}</td><td class="px-3 py-2 text-right">{{ kwanza(d.cost) }}</td>
                        </tr>
                        <tr v-if="report.by_department.length === 0"><td colspan="4" class="px-3 py-3 text-ink-muted">Sem serviços no período.</td></tr>
                    </tbody>
                </table>
            </div>

            <h2 class="mt-6 text-title">Por colaborador</h2>
            <div class="card mt-2 overflow-x-auto p-0">
                <table class="w-full text-caption">
                    <thead class="bg-navy-50 text-left"><tr><th class="px-3 py-2">Colaborador</th><th class="px-3 py-2">Departamento</th><th class="px-3 py-2 text-right">Serviços</th><th class="px-3 py-2 text-right">Horas</th><th class="px-3 py-2 text-right">Custo</th></tr></thead>
                    <tbody>
                        <tr v-for="e in report.by_employee" :key="e.name" class="border-t border-border">
                            <td class="px-3 py-2">{{ e.name }}</td><td class="px-3 py-2">{{ e.department }}</td><td class="px-3 py-2 text-right">{{ e.lessons }}</td><td class="px-3 py-2 text-right">{{ hours(e.hours) }}</td><td class="px-3 py-2 text-right">{{ kwanza(e.cost) }}</td>
                        </tr>
                        <tr v-if="report.by_employee.length === 0"><td colspan="5" class="px-3 py-3 text-ink-muted">Sem serviços no período.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <a :href="`/api/v1/companies/${companyId}/reports/services/export?format=pdf&${query}`" class="btn-secondary">Descarregar PDF</a>
                <a :href="`/api/v1/companies/${companyId}/reports/services/export?format=csv&${query}`" class="btn-outline">Excel (CSV)</a>
            </div>
        </template>
    </template>
</template>
