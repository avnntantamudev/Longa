<script setup lang="ts">
import AppIcon, { type IconName } from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import { api, ApiError } from '@/lib/api';
import type { RoleName } from '@/types/global';
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

type ProfileKey = Exclude<RoleName, 'admin'>;

const page = usePage();
const profiles: Array<{ key: ProfileKey; title: string; description: string; icon: IconName }> = [
    { key: 'client', title: 'Sou Cliente', description: 'Quero encontrar técnicos e marcar serviços.', icon: 'search' },
    { key: 'provider', title: 'Sou Técnico', description: 'Quero prestar serviços e receber por eles.', icon: 'user' },
    { key: 'company_rh', title: 'Sou Empresa (RH)', description: 'Quero contratar serviços para a empresa.', icon: 'calendar' },
];

const form = reactive({
    name: page.props.auth.user?.name ?? '',
    profiles: [] as ProfileKey[],
    accept_terms: false,
    accept_privacy: false,
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

function toggle(key: ProfileKey): void {
    form.profiles = form.profiles.includes(key) ? form.profiles.filter((p) => p !== key) : [...form.profiles, key];
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    try {
        await api.post('/onboarding', form);
        // Próximo passo conforme o perfil escolhido
        const next = form.profiles.includes('provider') ? '/onboarding/tecnico' : form.profiles.includes('company_rh') ? '/onboarding/empresa' : '/';
        router.visit(next);
    } catch (error) {
        if (error instanceof ApiError) {
            const fields = error.fieldErrors();
            errors.value = Object.keys(fields).length ? fields : { general: error.message };
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head title="Criar conta" />

    <h1 class="text-display">Bem-vindo ao BISCA</h1>
    <p class="mt-1 text-ink-muted">Falta pouco. Diga-nos como se chama e como quer usar o BISCA.</p>

    <form class="mt-6 grid gap-5" novalidate @submit.prevent="submit">
        <FormField label="Nome completo" for="name" :error="errors.name">
            <input id="name" v-model="form.name" class="input" autocomplete="name" placeholder="Ex.: Maria Silva" />
        </FormField>

        <fieldset class="grid gap-2">
            <legend class="mb-1.5 text-caption font-semibold">Perfil (pode escolher mais do que um)</legend>
            <button
                v-for="profile in profiles"
                :key="profile.key"
                type="button"
                class="card flex items-center gap-3 text-left ring-2 transition"
                :class="form.profiles.includes(profile.key) ? 'ring-orange-500' : 'ring-transparent'"
                :aria-pressed="form.profiles.includes(profile.key)"
                @click="toggle(profile.key)"
            >
                <span
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-full"
                    :class="form.profiles.includes(profile.key) ? 'bg-orange-500 text-white' : 'bg-orange-100 text-orange-600'"
                >
                    <AppIcon :name="profile.icon" />
                </span>
                <span>
                    <span class="block font-semibold">{{ profile.title }}</span>
                    <span class="block text-caption text-ink-muted">{{ profile.description }}</span>
                </span>
            </button>
            <p v-if="errors.profiles" class="text-caption text-danger" role="alert">{{ errors.profiles }}</p>
        </fieldset>

        <div class="grid gap-3">
            <label class="flex items-start gap-3 text-caption">
                <input v-model="form.accept_terms" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
                <span>Li e aceito os Termos de Utilização do BISCA.</span>
            </label>
            <p v-if="errors.accept_terms" class="text-caption text-danger">{{ errors.accept_terms }}</p>
            <label class="flex items-start gap-3 text-caption">
                <input v-model="form.accept_privacy" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
                <span>Li e aceito a Política de Privacidade (tratamento dos meus dados pessoais).</span>
            </label>
            <p v-if="errors.accept_privacy" class="text-caption text-danger">{{ errors.accept_privacy }}</p>
        </div>

        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="submitting">{{ submitting ? 'A guardar…' : 'Continuar' }}</button>
    </form>
</template>
