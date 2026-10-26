<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import { useGeolocation } from '@/composables/useGeolocation';
import { api, ApiError } from '@/lib/api';
import type { Skill, Zone } from '@/types/global';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{ skills: Skill[]; zones: Zone[] }>();

interface TrainerProfile {
    bio: string | null;
    years_experience: number | null;
    modalities: string[];
    rate_amount: number | null;
    rate_unit: string | null;
    municipality: string | null;
    neighborhood: string | null;
    latitude: number | null;
    longitude: number | null;
    service_radius_m: number;
    verification_status: string;
    skills?: Skill[];
}

const steps = ['Sobre si', 'Áreas', 'Local', 'Verificação'] as const;
const step = ref(0);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const details = reactive({ bio: '', years_experience: null as number | null, modalities: ['in_person'] as string[], rate_amount: null as number | null, rate_unit: 'session' });
const selectedSkills = ref<number[]>([]);
const location = reactive({ municipality: '', neighborhood: '', latitude: null as number | null, longitude: null as number | null, service_radius_m: 5000, accept_location: false });
const verification = reactive({ document_number: '', files: [] as File[] });
const verificationStatus = ref('unverified');
const page = usePage();
const photo = ref<string | null>(page.props.auth.user?.photo_url ?? null);

const geo = useGeolocation();
const zoneKey = ref('');

const skillsByCategory = computed(() => {
    const groups: Record<string, Skill[]> = {};
    for (const skill of props.skills) {
        (groups[skill.category] ??= []).push(skill);
    }
    return groups;
});

onMounted(async () => {
    const { data } = await api.get<{ data: TrainerProfile }>('/me/trainer-profile');
    details.bio = data.bio ?? '';
    details.years_experience = data.years_experience;
    details.modalities = data.modalities.length ? data.modalities : ['in_person'];
    details.rate_amount = data.rate_amount;
    details.rate_unit = data.rate_unit ?? 'session';
    selectedSkills.value = (data.skills ?? []).map((s) => s.id);
    Object.assign(location, {
        municipality: data.municipality ?? '',
        neighborhood: data.neighborhood ?? '',
        latitude: data.latitude,
        longitude: data.longitude,
        service_radius_m: data.service_radius_m,
    });
    verificationStatus.value = data.verification_status;
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
        errors.value = { photo: 'Carregue a sua foto: os alunos veem-na e a LONGA compara-a com o BI.' };
        return;
    }
    const actions = [
        () => api.put('/me/trainer-profile/details', details),
        () => api.put('/me/trainer-profile/skills', { skill_ids: selectedSkills.value }),
        () => api.put('/me/trainer-profile/location', location),
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

function toggleSkill(id: number): void {
    selectedSkills.value = selectedSkills.value.includes(id) ? selectedSkills.value.filter((s) => s !== id) : [...selectedSkills.value, id];
}

function toggleModality(value: string): void {
    details.modalities = details.modalities.includes(value) ? details.modalities.filter((m) => m !== value) : [...details.modalities, value];
}

async function useMyLocation(): Promise<void> {
    await geo.locate();
    if (geo.coords.value) {
        location.latitude = geo.coords.value.latitude;
        location.longitude = geo.coords.value.longitude;
    }
}

function chooseZone(): void {
    const zone = props.zones.find((z) => `${z.municipality}|${z.neighborhood}` === zoneKey.value);
    if (zone) {
        Object.assign(location, { municipality: zone.municipality, neighborhood: zone.neighborhood });
        if (geo.status.value !== 'ok') {
            location.latitude = zone.lat;
            location.longitude = zone.lng;
        }
    }
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
            <section v-for="(group, category) in skillsByCategory" :key="category">
                <h2 class="mb-2 text-caption font-semibold text-ink-muted">{{ category }}</h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="skill in group"
                        :key="skill.id"
                        type="button"
                        class="chip"
                        :class="{ 'chip-active': selectedSkills.includes(skill.id) }"
                        :aria-pressed="selectedSkills.includes(skill.id)"
                        @click="toggleSkill(skill.id)"
                    >
                        {{ skill.name }}
                    </button>
                </div>
            </section>
            <p v-if="errors.skill_ids" class="text-caption text-danger">{{ errors.skill_ids }}</p>
        </template>

        <!-- 3. Local -->
        <template v-else-if="step === 2">
            <h1 class="text-title">Onde dá aulas?</h1>
            <p class="-mt-3 text-caption text-ink-muted">
                Usamos a localização só para lhe enviar pedidos perto de si. Os alunos veem apenas o bairro, nunca a sua morada.
            </p>
            <button type="button" class="btn-secondary w-full" :disabled="geo.status.value === 'locating'" @click="useMyLocation">
                <AppIcon name="map-pin" :size="20" />
                {{ geo.status.value === 'locating' ? 'A obter localização…' : 'Usar a minha localização' }}
            </button>
            <p v-if="geo.status.value === 'ok'" class="text-caption text-success">Localização obtida (precisão ~{{ geo.coords.value?.accuracy }} m).</p>
            <p v-else-if="geo.status.value === 'denied'" class="text-caption text-warning">Sem permissão de localização. Escolha a zona abaixo.</p>
            <p v-else-if="geo.status.value === 'unavailable'" class="text-caption text-warning">Não foi possível obter a localização. Escolha a zona abaixo.</p>

            <FormField label="Zona" for="zone" :error="errors.municipality || errors.neighborhood">
                <select id="zone" v-model="zoneKey" class="input" @change="chooseZone">
                    <option value="" disabled>Escolha o bairro</option>
                    <option v-for="zone in zones" :key="`${zone.municipality}|${zone.neighborhood}`" :value="`${zone.municipality}|${zone.neighborhood}`">
                        {{ zone.neighborhood }} ({{ zone.municipality }})
                    </option>
                </select>
            </FormField>
            <p v-if="errors.latitude" class="text-caption text-danger">{{ errors.latitude }}</p>

            <FormField :label="`Raio de atuação: ${(location.service_radius_m / 1000).toFixed(0)} km`" for="radius">
                <input id="radius" v-model.number="location.service_radius_m" type="range" min="1000" max="20000" step="1000" class="accent-orange-500" />
            </FormField>

            <label class="flex items-start gap-3 text-caption">
                <input v-model="location.accept_location" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
                <span>Autorizo a LONGA a usar a minha localização para encontrar alunos perto de mim.</span>
            </label>
            <p v-if="errors.accept_location" class="text-caption text-danger">{{ errors.accept_location }}</p>
        </template>

        <!-- 4. Verificação -->
        <template v-else>
            <h1 class="text-title">Verificação LONGA</h1>
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
