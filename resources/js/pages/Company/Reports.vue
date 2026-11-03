<script setup lang="ts">
import CompanyTabs from '@/components/CompanyTabs.vue';
import { api } from '@/lib/api';
import { kwanza } from '@/lib/format';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Relatório RH dos colaboradores que autorizaram a empresa:
 * serviços que pediram como clientes (custo) e serviços que prestaram como técnicos (faturado).
 */
interface Company {
    id: number;
    name: string;
}
interface Report {
    totals: { employees: number; consented: number; lessons: number; hours: number; cost: number };
    by_department: { department: string; employees: number; lessons: number; hours: number; cost: number }[];
    by_employee: { name: string; department: string; lessons: number; hours: number; cost: number }[];
    provided: {
        totals: { services: number; hours: number; amount: number; net: number };
        by_employee: { name: string; department: string; services: number; hours: number; amount: number; net: number }[];
        services: { date: string; employee: string; department: string; category: string; hours: number; amount: number; net: number }[];
    };
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

    <CompanyTabs />

    <h1 class="text-display">Serviços da equipa</h1>
    <p class="mt-1 text-caption text-ink-muted">Só aparecem colaboradores ativos que autorizaram a empresa na app. Contam os serviços pagos no período.</p>

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
            <p class="mt-4 text-caption text-ink-muted">Autorizaram: {{ report.totals.consented }} de {{ report.totals.employees }} colaboradores</p>

            <!-- Prestados: o que os técnicos da equipa fizeram -->
            <section class="mt-4" data-testid="report-provided">
                <h2 class="text-title">Serviços prestados</h2>
                <p class="text-caption text-ink-muted">Feitos pelos colaboradores como técnicos.</p>
                <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="card"><p class="text-caption text-ink-muted">Serviços</p><p class="text-title">{{ report.provided.totals.services }}</p></div>
                    <div class="card"><p class="text-caption text-ink-muted">Horas</p><p class="text-title">{{ hours(report.provided.totals.hours) }}</p></div>
                    <div class="card"><p class="text-caption text-ink-muted">Faturado</p><p class="text-title">{{ kwanza(report.provided.totals.amount) }}</p></div>
                    <div class="card"><p class="text-caption text-ink-muted">Líquido (após comissão)</p><p class="text-title">{{ kwanza(report.provided.totals.net) }}</p></div>
                </div>

                <div class="card mt-3 overflow-x-auto p-0">
                    <table class="w-full text-caption">
                        <thead class="bg-navy-50 text-left"><tr><th class="px-3 py-2">Técnico</th><th class="px-3 py-2 text-right">Serviços</th><th class="px-3 py-2 text-right">Horas</th><th class="px-3 py-2 text-right">Faturado</th><th class="px-3 py-2 text-right">Líquido</th></tr></thead>
                        <tbody>
                            <tr v-for="e in report.provided.by_employee" :key="e.name" class="border-t border-border">
                                <td class="px-3 py-2">{{ e.name }}<span class="block text-ink-muted">{{ e.department }}</span></td><td class="px-3 py-2 text-right">{{ e.services }}</td><td class="px-3 py-2 text-right">{{ hours(e.hours) }}</td><td class="px-3 py-2 text-right">{{ kwanza(e.amount) }}</td><td class="px-3 py-2 text-right">{{ kwanza(e.net) }}</td>
                            </tr>
                            <tr v-if="report.provided.by_employee.length === 0"><td colspan="5" class="px-3 py-3 text-ink-muted">Sem serviços prestados no período.</td></tr>
                        </tbody>
                    </table>
                </div>

                <details v-if="report.provided.services.length" class="card mt-3">
                    <summary class="cursor-pointer font-semibold">Ver os {{ report.provided.services.length }} serviços</summary>
                    <ul class="mt-2 grid gap-2">
                        <li v-for="(s, i) in report.provided.services" :key="i" class="flex justify-between gap-3 border-t border-border pt-2 text-caption">
                            <span class="min-w-0"><strong>{{ s.date }}</strong> · {{ s.employee }}<span class="block truncate text-ink-muted">{{ s.category }} · {{ hours(s.hours) }}</span></span>
                            <span class="shrink-0 text-right">{{ kwanza(s.amount) }}<span class="block text-ink-muted">líq. {{ kwanza(s.net) }}</span></span>
                        </li>
                    </ul>
                </details>
            </section>

            <!-- Pedidos: o que os colaboradores pediram como clientes -->
            <section class="mt-8" data-testid="report-requested">
                <h2 class="text-title">Serviços pedidos</h2>
                <p class="text-caption text-ink-muted">Pedidos pelos colaboradores como clientes (custo).</p>
                <div class="mt-2 grid grid-cols-3 gap-3">
                    <div class="card"><p class="text-caption text-ink-muted">Serviços</p><p class="text-title">{{ report.totals.lessons }}</p></div>
                    <div class="card"><p class="text-caption text-ink-muted">Horas</p><p class="text-title">{{ hours(report.totals.hours) }}</p></div>
                    <div class="card"><p class="text-caption text-ink-muted">Custo</p><p class="text-title">{{ kwanza(report.totals.cost) }}</p></div>
                </div>

                <h3 class="mt-4 font-semibold">Por departamento</h3>
                <div class="card mt-2 overflow-x-auto p-0">
                    <table class="w-full text-caption">
                        <thead class="bg-navy-50 text-left"><tr><th class="px-3 py-2">Departamento</th><th class="px-3 py-2 text-right">Serviços</th><th class="px-3 py-2 text-right">Horas</th><th class="px-3 py-2 text-right">Custo</th></tr></thead>
                        <tbody>
                            <tr v-for="d in report.by_department" :key="d.department" class="border-t border-border">
                                <td class="px-3 py-2">{{ d.department }}</td><td class="px-3 py-2 text-right">{{ d.lessons }}</td><td class="px-3 py-2 text-right">{{ hours(d.hours) }}</td><td class="px-3 py-2 text-right">{{ kwanza(d.cost) }}</td>
                            </tr>
                            <tr v-if="report.by_department.length === 0"><td colspan="4" class="px-3 py-3 text-ink-muted">Sem serviços pedidos no período.</td></tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="mt-4 font-semibold">Por colaborador</h3>
                <div class="card mt-2 overflow-x-auto p-0">
                    <table class="w-full text-caption">
                        <thead class="bg-navy-50 text-left"><tr><th class="px-3 py-2">Colaborador</th><th class="px-3 py-2">Departamento</th><th class="px-3 py-2 text-right">Serviços</th><th class="px-3 py-2 text-right">Horas</th><th class="px-3 py-2 text-right">Custo</th></tr></thead>
                        <tbody>
                            <tr v-for="e in report.by_employee" :key="e.name" class="border-t border-border">
                                <td class="px-3 py-2">{{ e.name }}</td><td class="px-3 py-2">{{ e.department }}</td><td class="px-3 py-2 text-right">{{ e.lessons }}</td><td class="px-3 py-2 text-right">{{ hours(e.hours) }}</td><td class="px-3 py-2 text-right">{{ kwanza(e.cost) }}</td>
                            </tr>
                            <tr v-if="report.by_employee.length === 0"><td colspan="5" class="px-3 py-3 text-ink-muted">Sem serviços pedidos no período.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="mt-6 grid grid-cols-2 gap-3">
                <a :href="`/api/v1/companies/${companyId}/reports/services/export?format=pdf&${query}`" class="btn-secondary">Descarregar PDF</a>
                <a :href="`/api/v1/companies/${companyId}/reports/services/export?format=csv&${query}`" class="btn-outline">Excel (CSV)</a>
            </div>
        </template>
    </template>
</template>
