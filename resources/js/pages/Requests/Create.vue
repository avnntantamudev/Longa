<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import { useGeolocation } from '@/composables/useGeolocation';
import { api, ApiError } from '@/lib/api';
import { kwanza, toLocalInput } from '@/lib/format';
import type { Skill, Zone } from '@/types/global';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ skills: Skill[]; zones: Zone[]; minAmount: number; minLeadMinutes: number; commissionPercent: number }>();

// Sugestão: daqui a duas horas, arredondado à hora
const suggested = new Date(Date.now() + 2 * 3600_000);
suggested.setMinutes(0, 0, 0);

const form = reactive({
    skill_id: null as number | null,
    title: '',
    description: '',
    amount: null as number | null,
    scheduled_at: toLocalInput(suggested),
    duration_minutes: 60,
    payment_method: 'electronic' as 'electronic' | 'cash',
    modality: 'in_person' as 'in_person' | 'online',
    municipality: '',
    neighborhood: '',
    latitude: null as number | null,
    longitude: null as number | null,
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const geo = useGeolocation();
const zoneKey = ref('');

const minDate = toLocalInput(new Date(Date.now() + props.minLeadMinutes * 60_000));
const hasLocation = computed(() => form.latitude !== null && form.neighborhood !== '');

function onSkill(): void {
    const skill = props.skills.find((s) => s.id === form.skill_id);
    if (skill && form.title === '') {
        form.title = `Aula de ${skill.name.split(' — ')[0]}`;
    }
}

async function useMyLocation(): Promise<void> {
    await geo.locate();
    if (geo.coords.value) {
        form.latitude = geo.coords.value.latitude;
        form.longitude = geo.coords.value.longitude;
        // Bairro mais próximo das zonas conhecidas (o aluno pode corrigir)
        const nearest = [...props.zones].sort(
            (a, b) => Math.hypot(a.lat - form.latitude!, a.lng - form.longitude!) - Math.hypot(b.lat - form.latitude!, b.lng - form.longitude!),
        )[0];
        if (nearest) {
            zoneKey.value = `${nearest.municipality}|${nearest.neighborhood}`;
            form.municipality = nearest.municipality;
            form.neighborhood = nearest.neighborhood;
        }
    }
}

function chooseZone(): void {
    const zone = props.zones.find((z) => `${z.municipality}|${z.neighborhood}` === zoneKey.value);
    if (zone) {
        form.municipality = zone.municipality;
        form.neighborhood = zone.neighborhood;
        if (geo.status.value !== 'ok') {
            form.latitude = zone.lat;
            form.longitude = zone.lng;
        }
    }
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post<{ data: { id: number } }>('/training-requests', {
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
    <Head title="Pedir aula" />

    <h1 class="text-display">Pedir aula</h1>
    <p class="mt-1 text-ink-muted">Enviamos o pedido aos formadores verificados num raio de 5 km. O primeiro que aceitar fica com a aula.</p>

    <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
        <FormField label="O que quer aprender?" for="skill" :error="errors.skill_id">
            <select id="skill" v-model.number="form.skill_id" class="input" @change="onSkill">
                <option :value="null" disabled>Escolha a área</option>
                <option v-for="skill in skills" :key="skill.id" :value="skill.id">{{ skill.name }}</option>
            </select>
        </FormField>

        <FormField label="Tema" for="title" :error="errors.title">
            <input id="title" v-model="form.title" class="input" maxlength="120" placeholder="Ex.: Aula de SGME" />
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
                <button type="button" class="chip" :class="{ 'chip-active': form.payment_method === 'electronic' }" @click="form.payment_method = 'electronic'">Pela LONGA</button>
                <button type="button" class="chip" :class="{ 'chip-active': form.payment_method === 'cash' }" @click="form.payment_method = 'cash'">Em mão</button>
            </div>
            <p class="text-caption text-ink-muted">
                {{ form.payment_method === 'electronic'
                    ? 'Paga quando o formador aceitar. O valor fica retido e só é libertado depois de confirmar a aula com o QR.'
                    : 'Paga ao formador no dia da aula. Confirma na app depois da aula.' }}
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
            <p class="font-semibold">Onde vai ser a aula?</p>
            <p class="-mt-2 text-caption text-ink-muted">Os formadores só veem o bairro e a distância aproximada até aceitarem.</p>
            <button type="button" class="btn-secondary w-full" :disabled="geo.status.value === 'locating'" @click="useMyLocation">
                <AppIcon name="map-pin" :size="20" />
                {{ geo.status.value === 'locating' ? 'A obter localização…' : 'Usar a minha localização' }}
            </button>
            <p v-if="geo.status.value === 'denied' || geo.status.value === 'unavailable'" class="text-caption text-warning">
                Não foi possível usar a localização. Escolha o bairro abaixo.
            </p>
            <FormField label="Bairro" for="zone" :error="errors.neighborhood || errors.latitude">
                <select id="zone" v-model="zoneKey" class="input" @change="chooseZone">
                    <option value="" disabled>Escolha o bairro</option>
                    <option v-for="zone in zones" :key="`${zone.municipality}|${zone.neighborhood}`" :value="`${zone.municipality}|${zone.neighborhood}`">
                        {{ zone.neighborhood }} ({{ zone.municipality }})
                    </option>
                </select>
            </FormField>
        </fieldset>

        <p v-if="form.amount" class="text-caption text-ink-muted">
            Paga {{ kwanza(form.amount) }}. A comissão LONGA ({{ commissionPercent }}%) é descontada ao formador.
        </p>

        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving || !hasLocation || !form.skill_id">
            {{ saving ? 'A publicar…' : 'Procurar formador' }}
        </button>
    </form>
</template>
