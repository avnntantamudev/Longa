<script setup lang="ts">
import FormField from '@/components/FormField.vue';
import LocationPicker from '@/components/LocationPicker.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import { api, ApiError } from '@/lib/api';
import type { Category, Zone } from '@/types/global';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{ categories: Category[]; zones: Zone[]; provinces: string[] }>();

interface ProviderProfile {
    bio: string | null;
    years_experience: number | null;
    modalities: string[];
    rate_amount: number | null;
    rate_unit: string | null;
    province: string;
    municipality: string | null;
    neighborhood: string | null;
    latitude: number | null;
    longitude: number | null;
    service_radius_m: number;
    verification_status: string;
    categories?: Category[];
}

const steps = ['Sobre si', 'Áreas', 'Local', 'Verificação'] as const;
const step = ref(0);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const details = reactive({ bio: '', years_experience: null as number | null, modalities: ['in_person'] as string[], rate_amount: null as number | null, rate_unit: 'session' });
const selectedCategories = ref<number[]>([]);
const location = reactive({ province: 'Luanda', municipality: '', neighborhood: '', latitude: null as number | null, longitude: null as number | null, service_radius_m: 5000, accept_location: false });
const verification = reactive({ document_number: '', files: [] as File[] });
const verificationStatus = ref('unverified');

interface Suggestion {
    id: number;
    name: string;
    status: 'pending' | 'approved' | 'rejected';
    category: { id: number; name: string } | null;
    review_note: string | null;
}
const suggestions = ref<Suggestion[]>([]);
const otherCategory = ref('');
const suggesting = ref(false);

async function loadSuggestions(): Promise<void> {
    suggestions.value = (await api.get<{ data: Suggestion[] }>('/me/provider-profile/category-suggestions')).data;
}

async function suggestCategory(): Promise<void> {
    if (otherCategory.value.trim().length < 3) {
        errors.value = { other: 'Escreva o nome da área (pelo menos 3 caracteres).' };
        return;
    }
    suggesting.value = true;
    errors.value = {};
    try {
        const { data } = await api.post<{ data: Suggestion }>('/me/provider-profile/category-suggestions', { name: otherCategory.value.trim() });
        // Já existia na lista: fica selecionada
        if (data.status === 'approved' && data.category && !selectedCategories.value.includes(data.category.id)) {
            selectedCategories.value = [...selectedCategories.value, data.category.id];
        }
        otherCategory.value = '';
        await loadSuggestions();
    } catch (error) {
        errors.value = { other: error instanceof ApiError ? (Object.values(error.fieldErrors())[0] ?? error.message) : 'Sem ligação. Tente de novo.' };
    } finally {
        suggesting.value = false;
    }
}
const page = usePage();
const photo = ref<string | null>(page.props.auth.user?.photo_url ?? null);


const categoriesByCategory = computed(() => {
    const groups: Record<string, Category[]> = {};
    for (const category of props.categories) {
        (groups[category.area] ??= []).push(category);
    }
    return groups;
});

onMounted(async () => {
    const { data } = await api.get<{ data: ProviderProfile }>('/me/provider-profile');
    details.bio = data.bio ?? '';
    details.years_experience = data.years_experience;
    details.modalities = data.modalities.length ? data.modalities : ['in_person'];
    details.rate_amount = data.rate_amount;
    details.rate_unit = data.rate_unit ?? 'session';
    selectedCategories.value = (data.categories ?? []).map((s) => s.id);
    Object.assign(location, {
        province: data.province,
        municipality: data.municipality ?? '',
        neighborhood: data.neighborhood ?? '',
        latitude: data.latitude,
        longitude: data.longitude,
        service_radius_m: data.service_radius_m,
    });
    verificationStatus.value = data.verification_status;
    await loadSuggestions();
});

async function save(action: () => Promise<unknown>): Promise<boolean> {
    saving.value = true;
    errors.value = {};
    try {
        await action();
        return true;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = Object.keys(error.fieldErrors()).length ? error.fieldErrors() : { general: error.message };
        }
        return false;
    } finally {
        saving.value = false;
    }
}

async function next(): Promise<void> {
    if (step.value === 0 && !photo.value) {
        errors.value = { photo: 'Carregue a sua foto: os alunos veem-na e o BISCA compara-a com o BI.' };
        return;
    }
    const actions = [
        () => api.put('/me/provider-profile/details', details),
        () => (selectedCategories.value.length === 0 && suggestions.value.some((x) => x.status === 'pending') ? Promise.resolve() : api.put('/me/provider-profile/categories', { category_ids: selectedCategories.value })),
        () => api.put('/me/provider-profile/location', location),
        () => submitVerification(),
    ];
    if (await save(actions[step.value]!)) {
        if (step.value < steps.length - 1) {
            step.value++;
        } else {
            router.visit('/perfil');
        }
    }
}

function toggleCategory(id: number): void {
    selectedCategories.value = selectedCategories.value.includes(id) ? selectedCategories.value.filter((s) => s !== id) : [...selectedCategories.value, id];
}

function toggleModality(value: string): void {
    details.modalities = details.modalities.includes(value) ? details.modalities.filter((m) => m !== value) : [...details.modalities, value];
}

function onFiles(event: Event): void {
    verification.files = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, 3);
}

function submitVerification(): Promise<unknown> {
    const data = new FormData();
    data.append('document_number', verification.document_number);
    verification.files.forEach((file) => data.append('files[]', file));
    return api.post('/me/verifications', data);
}
</script>

<template>
    <Head title="Perfil de formador" />

    <p class="text-caption font-semibold text-orange-600">Passo {{ step + 1 }} de {{ steps.length }} · {{ steps[step] }}</p>
    <div class="mt-2 flex gap-1.5" aria-hidden="true">
        <span v-for="(s, i) in steps" :key="s" class="h-1.5 flex-1 rounded-full" :class="i <= step ? 'bg-orange-500' : 'bg-navy-100'" />
    </div>

    <form class="mt-6 grid gap-5" novalidate @submit.prevent="next">
        <!-- 1. Sobre si -->
        <template v-if="step === 0">
            <h1 class="text-title">Apresente-se aos alunos</h1>
            <div class="grid gap-1">
                <PhotoPicker :name="page.props.auth.user?.name" :src="photo" required @changed="(url) => (photo = url)" />
                <p v-if="errors.photo" class="text-caption text-danger" role="alert">{{ errors.photo }}</p>
            </div>
            <FormField label="Apresentação" for="bio" :error="errors.bio" hint="O que ensina, a quem e como. Mínimo 20 caracteres.">
                <textarea id="bio" v-model="details.bio" class="input min-h-32 py-3" maxlength="2000" />
            </FormField>
            <FormField label="Anos de experiência" for="exp" :error="errors.years_experience">
                <input id="exp" v-model.number="details.years_experience" class="input" type="number" min="0" max="60" inputmode="numeric" />
            </FormField>
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-caption font-semibold">Modalidades</legend>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="chip" :class="{ 'chip-active': details.modalities.includes('in_person') }" @click="toggleModality('in_person')">Presencial</button>
                    <button type="button" class="chip" :class="{ 'chip-active': details.modalities.includes('online') }" @click="toggleModality('online')">Online</button>
                </div>
                <p v-if="errors.modalities" class="text-caption text-danger">{{ errors.modalities }}</p>
            </fieldset>
            <div class="grid grid-cols-[1fr_auto] gap-3">
                <FormField label="Preço de referência (Kz)" for="rate" :error="errors.rate_amount">
                    <input id="rate" v-model.number="details.rate_amount" class="input" type="number" min="500" step="500" inputmode="numeric" placeholder="5000" />
                </FormField>
                <FormField label="Por" for="unit" :error="errors.rate_unit">
                    <select id="unit" v-model="details.rate_unit" class="input">
                        <option value="hour">hora</option>
                        <option value="two_hours">2 horas</option>
                        <option value="session">aula</option>
                        <option value="day">dia</option>
                    </select>
                </FormField>
            </div>
        </template>

        <!-- 2. Áreas -->
        <template v-else-if="step === 1">
            <h1 class="text-title">O que ensina?</h1>
            <p class="-mt-3 text-caption text-ink-muted">Escolha até 10 áreas. Só recebe pedidos destas áreas.</p>
            <section v-for="(group, area) in categoriesByCategory" :key="area">
                <h2 class="mb-2 text-caption font-semibold text-ink-muted">{{ area }}</h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="category in group"
                        :key="category.id"
                        type="button"
                        class="chip"
                        :class="{ 'chip-active': selectedCategories.includes(category.id) }"
                        :aria-pressed="selectedCategories.includes(category.id)"
                        @click="toggleCategory(category.id)"
                    >
                        {{ category.name }}
                    </button>
                </div>
            </section>
            <p v-if="errors.category_ids" class="text-caption text-danger">{{ errors.category_ids }}</p>

            <section class="card grid gap-2">
                <h2 class="font-semibold">Não encontra a sua área?</h2>
                <p class="text-caption text-ink-muted">Escreva-a. O BISCA analisa e, se aprovar, passa a receber pedidos dessa área.</p>
                <div class="flex gap-2">
                    <input v-model="otherCategory" class="input flex-1" maxlength="80" placeholder="Ex.: Contabilidade no Primavera" @keydown.enter.prevent="suggestCategory" />
                    <button type="button" class="btn-secondary min-h-12 px-4" :disabled="suggesting" @click="suggestCategory">Sugerir</button>
                </div>
                <p v-if="errors.other" class="text-caption text-danger" role="alert">{{ errors.other }}</p>
                <ul v-if="suggestions.length" class="grid gap-1 text-caption">
                    <li v-for="item in suggestions" :key="item.id" class="flex items-center justify-between gap-2 rounded-control bg-navy-50 px-3 py-2">
                        <span class="truncate">{{ item.name }}<template v-if="item.status === 'approved' && item.category && item.category.name !== item.name"> → {{ item.category.name }}</template></span>
                        <span class="badge shrink-0" :class="{ 'badge-warning': item.status === 'pending', 'badge-success': item.status === 'approved', 'badge-neutral': item.status === 'rejected' }">
                            {{ item.status === 'pending' ? 'Em análise' : item.status === 'approved' ? 'Aprovada' : 'Não aprovada' }}
                        </span>
                    </li>
                </ul>
                <p v-if="selectedCategories.length === 0 && suggestions.some((x) => x.status === 'pending')" class="text-caption text-ink-muted">
                    Pode continuar: começa a receber pedidos quando a área for aprovada.
                </p>
            </section>
        </template>

        <!-- 3. Local -->
        <template v-else-if="step === 2">
            <h1 class="text-title">Onde dá aulas?</h1>
            <p class="-mt-3 text-caption text-ink-muted">
                Usamos a localização só para lhe enviar pedidos perto de si. Marque no mapa o ponto de onde parte (pode ser aproximado). Os alunos veem apenas o bairro, nunca o ponto.
            </p>
            <LocationPicker :value="location" :zones="zones" :provinces="provinces" :radius-m="location.service_radius_m" :errors="errors" />

            <FormField :label="`Raio de atuação: ${(location.service_radius_m / 1000).toFixed(0)} km`" for="radius">
                <input id="radius" v-model.number="location.service_radius_m" type="range" min="1000" max="20000" step="1000" class="accent-orange-500" />
            </FormField>

            <label class="flex items-start gap-3 text-caption">
                <input v-model="location.accept_location" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
                <span>Autorizo o BISCA a usar a minha localização para encontrar alunos perto de mim.</span>
            </label>
            <p v-if="errors.accept_location" class="text-caption text-danger">{{ errors.accept_location }}</p>
        </template>

        <!-- 4. Verificação -->
        <template v-else>
            <h1 class="text-title">Verificação BISCA</h1>
            <p class="-mt-3 text-caption text-ink-muted">
                Só formadores verificados aparecem na pesquisa e recebem pedidos. O seu BI fica cifrado e só a equipa de verificação o vê.
            </p>
            <div v-if="verificationStatus === 'pending'" class="card badge-warning">A sua verificação está em análise.</div>
            <template v-else>
                <FormField label="Nº do BI" for="bi" :error="errors.document_number" hint="Ex.: 001234567LA042">
                    <input id="bi" v-model="verification.document_number" class="input uppercase" autocomplete="off" maxlength="14" />
                </FormField>
                <FormField label="Fotografias do BI (frente e verso)" for="files" :error="errors.files || errors['files.0']" hint="JPG, PNG ou PDF até 5 MB.">
                    <input id="files" type="file" accept="image/jpeg,image/png,application/pdf" multiple capture="environment" class="input py-2.5" @change="onFiles" />
                </FormField>
            </template>
        </template>

        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>

        <div class="grid grid-cols-2 gap-3">
            <button type="button" class="btn-secondary" :disabled="step === 0 || saving" @click="step--">Voltar</button>
            <button v-if="step === 3 && verificationStatus === 'pending'" type="button" class="btn-primary" @click="router.visit('/perfil')">Concluir</button>
            <button v-else type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'A guardar…' : step === 3 ? 'Enviar' : 'Continuar' }}</button>
        </div>
        <button v-if="step === 3 && verificationStatus !== 'pending'" type="button" class="text-caption font-semibold text-navy-700 underline" @click="router.visit('/perfil')">
            Fazer a verificação mais tarde
        </button>
    </form>
</template>
