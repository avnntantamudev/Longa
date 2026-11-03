<script setup lang="ts">
/**
 * Gerir a equipa da empresa (RH): adicionar, editar, desativar e retirar funcionários,
 * e ver quem autorizou a empresa a acompanhar os seus serviços.
 */
import AppIcon from '@/components/AppIcon.vue';
import CompanyTabs from '@/components/CompanyTabs.vue';
import FormField from '@/components/FormField.vue';
import { api, ApiError } from '@/lib/api';
import { matchesSearch } from '@/lib/search';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';

interface Company {
    id: number;
    name: string;
}

type Consent = 'pending' | 'accepted' | 'declined' | 'revoked';

interface Employee {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    department: string | null;
    job_title: string | null;
    status: 'active' | 'inactive';
    has_longa_account: boolean;
    consent_status: Consent;
}

interface Paginated<T> {
    data: T[];
    meta?: { last_page: number };
}

const consentLabels: Record<Consent, { label: string; css: string }> = {
    pending: { label: 'A aguardar autorização', css: 'bg-warning-soft text-warning' },
    accepted: { label: 'Autorizou', css: 'bg-success-soft text-success' },
    declined: { label: 'Recusou', css: 'bg-danger-soft text-danger' },
    revoked: { label: 'Retirou a autorização', css: 'bg-danger-soft text-danger' },
};

const companies = ref<Company[]>([]);
const companyId = ref<number | null>(null);
const employees = ref<Employee[]>([]);
const loading = ref(true);
const search = ref('');
const pageError = ref('');

// Formulário (adicionar ou editar)
const emptyForm = () => ({ name: '', phone: '', department: '', job_title: '', email: '' });
const form = reactive(emptyForm());
const formOpen = ref(false);
const editingId = ref<number | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const notice = ref('');

const departments = computed(() => [...new Set(employees.value.map((e) => e.department).filter((d): d is string => !!d))].sort());
const filtered = computed(() => employees.value.filter((e) => matchesSearch(search.value, e.name, e.department, e.job_title, e.phone)));
const totals = computed(() => ({
    all: employees.value.length,
    accepted: employees.value.filter((e) => e.consent_status === 'accepted').length,
    pending: employees.value.filter((e) => e.consent_status === 'pending').length,
}));

async function loadEmployees(): Promise<void> {
    if (!companyId.value) return;
    loading.value = true;
    pageError.value = '';
    try {
        const all: Employee[] = [];
        for (let page = 1, last = 1; page <= last; page++) {
            const res = await api.get<Paginated<Employee>>(`/companies/${companyId.value}/employees?page=${page}`);
            all.push(...res.data);
            last = res.meta?.last_page ?? 1;
        }
        employees.value = all;
    } catch (e) {
        pageError.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    } finally {
        loading.value = false;
    }
}

function openAdd(): void {
    Object.assign(form, emptyForm());
    editingId.value = null;
    errors.value = {};
    notice.value = '';
    formOpen.value = true;
}

function openEdit(employee: Employee): void {
    Object.assign(form, {
        name: employee.name,
        phone: employee.phone ?? '',
        department: employee.department ?? '',
        job_title: employee.job_title ?? '',
        email: employee.email ?? '',
    });
    editingId.value = employee.id;
    errors.value = {};
    notice.value = '';
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingId.value = null;
}

async function save(): Promise<void> {
    if (!companyId.value) return;
    saving.value = true;
    errors.value = {};
    const payload = {
        name: form.name.trim(),
        phone: form.phone.trim() || null,
        department: form.department.trim() || null,
        job_title: form.job_title.trim() || null,
        email: form.email.trim() || null,
    };
    try {
        if (editingId.value) {
            await api.patch(`/companies/${companyId.value}/employees/${editingId.value}`, payload);
            notice.value = 'Dados atualizados.';
        } else {
            await api.post(`/companies/${companyId.value}/employees`, payload);
            notice.value = payload.phone
                ? `${payload.name} foi adicionado. Vai receber no BISCA o pedido para autorizar a empresa.`
                : `${payload.name} foi adicionado. Sem telefone não é possível pedir autorização nem incluí-lo nos relatórios.`;
        }
        closeForm();
        await loadEmployees();
    } catch (e) {
        const fields = e instanceof ApiError ? e.fieldErrors() : {};
        errors.value = Object.keys(fields).length ? fields : { general: e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.' };
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(employee: Employee): Promise<void> {
    pageError.value = '';
    try {
        await api.patch(`/companies/${companyId.value}/employees/${employee.id}`, { status: employee.status === 'active' ? 'inactive' : 'active' });
        await loadEmployees();
    } catch (e) {
        pageError.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    }
}

async function remove(employee: Employee): Promise<void> {
    if (!window.confirm(`Retirar ${employee.name} da equipa? Deixa de aparecer nos relatórios.`)) return;
    pageError.value = '';
    try {
        await api.delete(`/companies/${companyId.value}/employees/${employee.id}`);
        notice.value = `${employee.name} foi retirado da equipa.`;
        await loadEmployees();
    } catch (e) {
        pageError.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    }
}

watch(companyId, () => {
    closeForm();
    loadEmployees();
});

onMounted(async () => {
    try {
        companies.value = (await api.get<{ data: Company[] }>('/companies')).data;
        companyId.value = companies.value[0]?.id ?? null;
    } catch (e) {
        pageError.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    } finally {
        if (!companyId.value) loading.value = false;
    }
});
</script>

<template>
    <Head title="Equipa" />

    <CompanyTabs />

    <h1 class="text-display">Funcionários</h1>
    <p class="mt-1 text-caption text-ink-muted">
        Adicione os funcionários com o telefone que usam no BISCA. Cada um decide na app se autoriza a empresa a ver os serviços que pede e que presta como técnico, horas e valores (nunca o chat nem os clientes).
    </p>

    <p v-if="!loading && companies.length === 0" class="card mt-4 text-ink-muted">
        Ainda não gere nenhuma empresa. <Link href="/onboarding/empresa" class="font-semibold text-orange-600 underline">Criar empresa</Link>
    </p>

    <template v-else>
        <select v-if="companies.length > 1" v-model.number="companyId" class="input mt-4" aria-label="Empresa">
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>

        <div class="mt-4 grid grid-cols-3 gap-3" data-testid="team-totals">
            <div class="card"><p class="text-caption text-ink-muted">Funcionários</p><p class="text-title">{{ totals.all }}</p></div>
            <div class="card"><p class="text-caption text-ink-muted">Autorizaram</p><p class="text-title">{{ totals.accepted }}</p></div>
            <div class="card"><p class="text-caption text-ink-muted">A aguardar</p><p class="text-title">{{ totals.pending }}</p></div>
        </div>

        <p v-if="notice" class="mt-4 rounded-control bg-success-soft p-3 text-caption text-success" role="status">{{ notice }}</p>
        <p v-if="pageError" class="mt-4 rounded-control bg-danger-soft p-3 text-caption text-danger" role="alert">{{ pageError }}</p>

        <!-- Adicionar / editar -->
        <button v-if="!formOpen" type="button" class="btn-primary mt-4 w-full" @click="openAdd">Adicionar funcionário</button>

        <form v-else class="card mt-4 grid gap-3" novalidate @submit.prevent="save">
            <h2 class="font-semibold">{{ editingId ? 'Editar funcionário' : 'Novo funcionário' }}</h2>
            <FormField label="Nome" for="emp-name" :error="errors.name">
                <input id="emp-name" v-model="form.name" class="input" maxlength="160" autocomplete="off" required />
            </FormField>
            <FormField label="Telefone (o que usa no BISCA)" for="emp-phone" :error="errors.phone">
                <input id="emp-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" placeholder="923 000 000" autocomplete="off" />
            </FormField>
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Departamento" for="emp-department" :error="errors.department">
                    <input id="emp-department" v-model="form.department" class="input" maxlength="80" list="emp-departments" placeholder="Ex.: Operações" autocomplete="off" />
                    <datalist id="emp-departments">
                        <option v-for="d in departments" :key="d" :value="d" />
                    </datalist>
                </FormField>
                <FormField label="Cargo" for="emp-job" :error="errors.job_title">
                    <input id="emp-job" v-model="form.job_title" class="input" maxlength="80" autocomplete="off" />
                </FormField>
            </div>
            <FormField label="Email (opcional)" for="emp-email" :error="errors.email">
                <input id="emp-email" v-model="form.email" class="input" type="email" inputmode="email" maxlength="190" autocomplete="off" />
            </FormField>
            <p v-if="editingId && form.phone" class="text-caption text-ink-muted">Se mudar o telefone, o funcionário tem de voltar a autorizar a empresa.</p>
            <p v-if="errors.general" class="text-caption text-danger">{{ errors.general }}</p>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" class="btn-outline" @click="closeForm">Cancelar</button>
                <button type="submit" class="btn-primary" :disabled="saving || form.name.trim().length < 2">{{ saving ? 'A guardar…' : 'Guardar' }}</button>
            </div>
        </form>

        <!-- Lista -->
        <div v-if="employees.length > 5" class="relative mt-4">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-muted"><AppIcon name="search" :size="18" /></span>
            <input v-model="search" type="search" class="input pl-10" placeholder="Pesquisar por nome, departamento ou telefone" aria-label="Pesquisar funcionário" autocomplete="off" />
        </div>

        <p v-if="loading" class="mt-4 text-caption text-ink-muted">A carregar…</p>
        <p v-else-if="employees.length === 0" class="card mt-4 text-ink-muted">Ainda não adicionou funcionários.</p>
        <p v-else-if="filtered.length === 0" class="mt-4 text-caption text-ink-muted">Nenhum funcionário encontrado para "{{ search }}".</p>

        <ul class="mt-4 grid gap-3" data-testid="team-list">
            <li v-for="e in filtered" :key="e.id" class="card grid gap-2" :class="{ 'opacity-60': e.status === 'inactive' }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ e.name }}</p>
                        <p class="truncate text-caption text-ink-muted">{{ [e.job_title, e.department].filter(Boolean).join(' · ') || 'Sem departamento' }}</p>
                        <p v-if="e.phone" class="text-caption text-ink-muted">{{ e.phone }}</p>
                    </div>
                    <span v-if="e.phone" class="shrink-0 rounded-full px-2 py-1 text-caption" :class="consentLabels[e.consent_status].css">{{ consentLabels[e.consent_status].label }}</span>
                    <span v-else class="shrink-0 rounded-full bg-surface-muted px-2 py-1 text-caption text-ink-muted">Sem telefone</span>
                </div>
                <p v-if="e.phone && e.consent_status === 'pending' && !e.has_longa_account" class="text-caption text-ink-muted">
                    Ainda sem conta no BISCA: vê o pedido no Perfil quando se registar com este telefone.
                </p>
                <p v-if="e.status === 'inactive'" class="text-caption text-ink-muted">Desativado: não aparece nos relatórios.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="chip" @click="openEdit(e)">Editar</button>
                    <button type="button" class="chip" @click="toggleStatus(e)">{{ e.status === 'active' ? 'Desativar' : 'Reativar' }}</button>
                    <button type="button" class="chip text-danger" @click="remove(e)">Retirar</button>
                </div>
            </li>
        </ul>
    </template>
</template>
