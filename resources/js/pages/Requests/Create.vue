<script setup lang="ts">
import LocationPicker from '@/components/LocationPicker.vue';
import FormField from '@/components/FormField.vue';
import { api, ApiError } from '@/lib/api';
import { kwanza, toLocalInput } from '@/lib/format';
import type { Category, Zone } from '@/types/global';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ categories: Category[]; zones: Zone[]; minAmount: number; minLeadMinutes: number; commissionPercent: number; provinces: string[] }>();

// Sugestão: daqui a duas horas, arredondado à hora
const suggested = new Date(Date.now() + 2 * 3600_000);
suggested.setMinutes(0, 0, 0);

const form = reactive({
    category_id: null as number | null,
    title: '',
    description: '',
    amount: null as number | null,
    scheduled_at: toLocalInput(suggested),
    duration_minutes: 60,
    payment_method: 'electronic' as 'electronic' | 'cash',
    modality: 'in_person' as 'in_person' | 'online',
    province: 'Luanda',
    municipality: '',
    neighborhood: '',
    latitude: null as number | null,
    longitude: null as number | null,
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const minDate = toLocalInput(new Date(Date.now() + props.minLeadMinutes * 60_000));
const hasLocation = computed(() => form.latitude !== null && form.neighborhood !== '');

const categoriesByGroup = computed(() => {
    const groups: Record<string, Category[]> = {};
    for (const category of props.categories) {
        (groups[category.group ?? 'Outros'] ??= []).push(category);
    }
    return groups;
});

function onCategory(): void {
    const category = props.categories.find((s) => s.id === form.category_id);
    if (category && form.title === '') {
        form.title = `Serviço de ${category.name.split(' — ')[0]}`;
    }
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post<{ data: { id: number } }>('/service-requests', {
            ...form,
            scheduled_at: new Date(form.scheduled_at).toISOString(),
        });
        router.visit(`/pedidos/${data.id}`);
    } catch (error) {
        if (error instanceof ApiError) {
            const fields = error.fieldErrors();
            errors.value = Object.keys(fields).length ? fields : { general: error.message };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Pedir serviço" />

    <h1 class="text-display">Pedir serviço</h1>
    <p class="mt-1 text-ink-muted">Enviamos o pedido aos técnicos verificados num raio de 5 km. O primeiro que aceitar fica com o serviço.</p>

    <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
        <FormField label="Que serviço precisa?" for="category" :error="errors.category_id">
            <select id="category" v-model.number="form.category_id" class="input" @change="onCategory">
                <option :value="null" disabled>Escolha o serviço</option>
                <optgroup v-for="(group, groupName) in categoriesByGroup" :key="groupName" :label="groupName">
                    <option v-for="category in group" :key="category.id" :value="category.id">{{ category.name }}</option>
                </optgroup>
            </select>
        </FormField>

        <FormField label="Tema" for="title" :error="errors.title">
            <input id="title" v-model="form.title" class="input" maxlength="120" placeholder="Ex.: Serviço de SGME" />
        </FormField>

        <FormField label="Detalhes (opcional)" for="desc" :error="errors.description" hint="O que quer rever, o seu nível, materiais que tem.">
            <textarea id="desc" v-model="form.description" class="input min-h-24 py-3" maxlength="1000" />
        </FormField>

        <div class="grid gap-3 sm:grid-cols-2">
            <FormField label="Valor (Kz)" for="amount" :error="errors.amount" :hint="`Mínimo ${kwanza(minAmount)}`">
                <input id="amount" v-model.number="form.amount" class="input" type="number" inputmode="numeric" :min="minAmount" step="500" placeholder="5000" />
            </FormField>
            <FormField label="Quando" for="when" :error="errors.scheduled_at">
                <input id="when" v-model="form.scheduled_at" class="input" type="datetime-local" :min="minDate" />
            </FormField>
        </div>

        <FormField label="Duração" for="duration" :error="errors.duration_minutes">
            <select id="duration" v-model.number="form.duration_minutes" class="input">
                <option v-for="m in [30, 45, 60, 90, 120, 180, 240]" :key="m" :value="m">{{ m < 60 ? `${m} min` : `${m / 60} h`.replace('.5', ',5') }}</option>
            </select>
        </FormField>

        <fieldset class="grid gap-2">
            <legend class="mb-1 text-caption font-semibold">Pagamento</legend>
            <div class="flex gap-2">
                <button type="button" class="chip" :class="{ 'chip-active': form.payment_method === 'electronic' }" @click="form.payment_method = 'electronic'">Pelo BISCA</button>
                <button type="button" class="chip" :class="{ 'chip-active': form.payment_method === 'cash' }" @click="form.payment_method = 'cash'">Em mão</button>
            </div>
            <p class="text-caption text-ink-muted">
                {{ form.payment_method === 'electronic'
                    ? 'Paga quando o técnico aceitar. O valor fica retido e só é libertado depois de confirmar o serviço com o QR.'
                    : 'Paga ao técnico no dia do serviço. Confirma na app depois do serviço.' }}
            </p>
        </fieldset>

        <fieldset class="grid gap-2">
            <legend class="mb-1 text-caption font-semibold">Modalidade</legend>
            <div class="flex gap-2">
                <button type="button" class="chip" :class="{ 'chip-active': form.modality === 'in_person' }" @click="form.modality = 'in_person'">Presencial</button>
                <button type="button" class="chip" :class="{ 'chip-active': form.modality === 'online' }" @click="form.modality = 'online'">Online</button>
            </div>
        </fieldset>

        <fieldset class="card grid gap-3">
            <legend class="sr-only">Local</legend>
            <p class="font-semibold">Onde vai ser o serviço?</p>
            <p class="-mt-2 text-caption text-ink-muted">Marque o local no mapa. Os técnicos só veem o bairro e a distância aproximada até aceitarem.</p>
            <LocationPicker :value="form" :zones="zones" :provinces="provinces" :errors="errors" />
        </fieldset>

        <p v-if="form.amount" class="text-caption text-ink-muted">
            Paga {{ kwanza(form.amount) }}. A comissão do BISCA ({{ commissionPercent }}%) é descontada ao técnico.
        </p>

        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving || !hasLocation || !form.category_id">
            {{ saving ? 'A publicar…' : 'Procurar técnico' }}
        </button>
    </form>
</template>
