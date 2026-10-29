<script setup lang="ts">
import LocationPicker from '@/components/LocationPicker.vue';
import FormField from '@/components/FormField.vue';
import { api, ApiError } from '@/lib/api';
import { kwanza, toLocalInput } from '@/lib/format';
import type { Category, Zone } from '@/types/global';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ categories: Category[]; zones: Zone[]; minAmount: number; minLeadMinutes: number; commissionPercent: number; provinces: string[] }>();

/** Formulário da categoria (GET /api/v1/categories/{id}/form) */
interface FormFieldDef {
    key: string;
    label: string;
    type: 'text' | 'number' | 'choice' | 'photos' | 'video';
    required: boolean;
    options: string[] | null;
    help: string | null;
}
interface CategoryForm {
    fields: FormFieldDef[];
    price: { fixed_price: number | null; reference_min: number | null; reference_max: number | null; hourly_min: number | null; hourly_max: number | null; visit_fee: number | null } | null;
}

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
// Respostas de texto, número e escolha; os ficheiros ficam à parte
const answers = reactive<Record<string, string | number | null>>({});
const files = reactive<Record<string, File[]>>({});
const categoryForm = ref<CategoryForm | null>(null);
const loadingForm = ref(false);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const minDate = toLocalInput(new Date(Date.now() + props.minLeadMinutes * 60_000));
const hasLocation = computed(() => form.latitude !== null && form.neighborhood !== '');
const category = computed(() => props.categories.find((c) => c.id === form.category_id) ?? null);
const mode = computed(() => category.value?.pricing_mode ?? null);
const modalities = computed<Array<'in_person' | 'online'>>(() => category.value?.modalities ?? ['in_person']);
const price = computed(() => categoryForm.value?.price ?? null);

const categoriesByGroup = computed(() => {
    const groups: Record<string, Category[]> = {};
    for (const c of props.categories) {
        (groups[c.group ?? 'Outros'] ??= []).push(c);
    }
    return groups;
});

async function onCategory(): Promise<void> {
    categoryForm.value = null;
    for (const key of Object.keys(answers)) delete answers[key];
    for (const key of Object.keys(files)) delete files[key];
    if (!category.value) return;
    if (!modalities.value.includes(form.modality)) form.modality = modalities.value[0] ?? 'in_person';
    loadingForm.value = true;
    try {
        categoryForm.value = (await api.get<{ data: CategoryForm }>(`/categories/${category.value.id}/form`)).data;
        for (const field of categoryForm.value.fields) {
            if (field.type === 'photos' || field.type === 'video') files[field.key] = [];
            else answers[field.key] = null;
        }
    } catch (error) {
        errors.value = { general: error instanceof ApiError ? error.message : 'Sem ligação. Tente de novo.' };
    } finally {
        loadingForm.value = false;
    }
}

function onFiles(key: string, event: Event): void {
    files[key] = Array.from((event.target as HTMLInputElement).files ?? []);
}

/** Corpo do pedido: multipart quando há ficheiros (answers[chave][] para fotos), JSON nos outros casos. */
function body(): FormData | Record<string, unknown> {
    const base: Record<string, unknown> = {
        ...form,
        title: form.title || null,
        amount: mode.value === 'hourly_package' ? form.amount : undefined,
        scheduled_at: new Date(form.scheduled_at).toISOString(),
    };
    const filled = Object.fromEntries(Object.entries(answers).filter(([, v]) => v !== null && v !== ''));
    const hasFiles = Object.values(files).some((list) => list.length > 0);
    if (!hasFiles) return { ...base, answers: filled };

    const data = new FormData();
    for (const [key, value] of Object.entries(base)) {
        if (value !== null && value !== undefined) data.append(key, String(value));
    }
    for (const [key, value] of Object.entries(filled)) data.append(`answers[${key}]`, String(value));
    for (const field of categoryForm.value?.fields ?? []) {
        const list = files[field.key] ?? [];
        if (field.type === 'video' && list[0]) data.append(`answers[${field.key}]`, list[0]);
        if (field.type === 'photos') list.forEach((file) => data.append(`answers[${field.key}][]`, file));
    }
    return data;
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post<{ data: { id: number } }>('/service-requests', body());
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

/** Primeiro erro de um campo de ficheiros (answers.fotos ou answers.fotos.0) */
function fileError(key: string): string | undefined {
    return errors.value[`answers.${key}`] ?? Object.entries(errors.value).find(([k]) => k.startsWith(`answers.${key}.`))?.[1];
}
</script>

<template>
    <Head title="Pedir serviço" />

    <h1 class="text-display">Pedir serviço</h1>
    <p class="mt-1 text-ink-muted">Enviamos o pedido aos técnicos verificados perto de si. O primeiro que aceitar fica com o serviço.</p>

    <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
        <FormField label="Que serviço precisa?" for="category" :error="errors.category_id">
            <select id="category" v-model.number="form.category_id" class="input" @change="onCategory">
                <option :value="null" disabled>Escolha o serviço</option>
                <optgroup v-for="(group, groupName) in categoriesByGroup" :key="groupName" :label="groupName">
                    <option v-for="c in group" :key="c.id" :value="c.id">{{ c.name }}</option>
                </optgroup>
            </select>
        </FormField>

        <p v-if="loadingForm" class="text-caption text-ink-muted">A carregar o formulário…</p>

        <template v-if="categoryForm">
            <!-- Preço conforme o modo da categoria -->
            <div v-if="mode === 'fixed' && price?.fixed_price" class="card" data-testid="fixed-price">
                <p class="text-caption text-ink-muted">Preço fixo</p>
                <p class="text-title">{{ kwanza(price.fixed_price) }}</p>
                <p class="text-caption text-ink-muted">O valor fica bloqueado quando enviar o pedido.</p>
            </div>
            <div v-else-if="mode === 'quote'" class="card">
                <p class="font-semibold">Preço por orçamento</p>
                <p class="text-caption text-ink-muted">
                    O técnico vê o problema no local e envia um orçamento com mão de obra, materiais e prazo. Só paga o serviço se aceitar.
                </p>
                <p v-if="price?.reference_min && price?.reference_max" class="mt-1 text-caption text-ink-muted">
                    Valores habituais: {{ kwanza(price.reference_min) }} a {{ kwanza(price.reference_max) }}.
                </p>
                <p v-if="price?.visit_fee" class="mt-1 text-caption text-ink-muted">
                    Taxa de visita: {{ kwanza(price.visit_fee) }} (descontada no serviço se aceitar o orçamento).
                </p>
            </div>

            <!-- Campos da categoria -->
            <template v-for="field in categoryForm.fields" :key="field.key">
                <FormField
                    :label="field.required ? field.label : `${field.label} (opcional)`"
                    :for="`f-${field.key}`"
                    :error="field.type === 'photos' || field.type === 'video' ? fileError(field.key) : errors[`answers.${field.key}`]"
                    :hint="field.help ?? undefined"
                >
                    <textarea v-if="field.type === 'text'" :id="`f-${field.key}`" v-model="answers[field.key]" class="input min-h-20 py-3" maxlength="1000" />
                    <input v-else-if="field.type === 'number'" :id="`f-${field.key}`" v-model.number="answers[field.key]" class="input" type="number" inputmode="numeric" min="0" />
                    <select v-else-if="field.type === 'choice'" :id="`f-${field.key}`" v-model="answers[field.key]" class="input">
                        <option :value="null" disabled>Escolha</option>
                        <option v-for="option in field.options ?? []" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <template v-else>
                        <input
                            :id="`f-${field.key}`"
                            class="input py-2"
                            type="file"
                            :accept="field.type === 'photos' ? 'image/jpeg,image/png,image/webp' : 'video/mp4,video/quicktime,video/webm'"
                            :multiple="field.type === 'photos'"
                            @change="onFiles(field.key, $event)"
                        />
                        <p v-if="files[field.key]?.length" class="mt-1 text-caption text-ink-muted">
                            {{ files[field.key]?.length === 1 ? '1 ficheiro escolhido' : `${files[field.key]?.length} ficheiros escolhidos` }}
                        </p>
                    </template>
                </FormField>
            </template>
        </template>

        <FormField label="Tema (opcional)" for="title" :error="errors.title" hint="Por omissão, o nome do serviço.">
            <input id="title" v-model="form.title" class="input" maxlength="120" :placeholder="category?.name ?? ''" />
        </FormField>

        <div class="grid gap-3 sm:grid-cols-2">
            <FormField v-if="mode === 'hourly_package'" label="Valor (Kz)" for="amount" :error="errors.amount" :hint="`Mínimo ${kwanza(minAmount)}`">
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
                    ? 'O valor fica retido pelo BISCA e só é libertado depois de confirmar o serviço.'
                    : 'Paga ao técnico no dia do serviço. Confirma na app depois do serviço.' }}
            </p>
        </fieldset>

        <fieldset v-if="modalities.length > 1" class="grid gap-2">
            <legend class="mb-1 text-caption font-semibold">Modalidade</legend>
            <div class="flex gap-2">
                <button v-if="modalities.includes('in_person')" type="button" class="chip" :class="{ 'chip-active': form.modality === 'in_person' }" @click="form.modality = 'in_person'">Presencial</button>
                <button v-if="modalities.includes('online')" type="button" class="chip" :class="{ 'chip-active': form.modality === 'online' }" @click="form.modality = 'online'">Online</button>
            </div>
            <p v-if="errors.modality" class="text-caption text-danger">{{ errors.modality }}</p>
        </fieldset>

        <fieldset class="card grid gap-3">
            <legend class="sr-only">Local</legend>
            <p class="font-semibold">Onde vai ser o serviço?</p>
            <p class="-mt-2 text-caption text-ink-muted">Confirme o local no mapa. Os técnicos só veem o bairro e a distância aproximada até aceitarem.</p>
            <LocationPicker :value="form" :zones="zones" :provinces="provinces" :errors="errors" />
        </fieldset>

        <p v-if="mode === 'hourly_package' && form.amount" class="text-caption text-ink-muted">
            Paga {{ kwanza(form.amount) }}. A comissão do BISCA ({{ commissionPercent }}%) é descontada ao técnico.
        </p>

        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving || !hasLocation || !form.category_id || loadingForm">
            {{ saving ? 'A publicar…' : 'Procurar técnico' }}
        </button>
    </form>
</template>
