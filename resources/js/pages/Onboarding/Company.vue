<script setup lang="ts">
import FormField from '@/components/FormField.vue';
import { api, ApiError } from '@/lib/api';
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const form = reactive({ name: '', nif: '', sector: '', municipality: '', neighborhood: '', address: '', phone: '', email: '' });
const errors = ref<Record<string, string>>({});
const saving = ref(false);

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    try {
        // Campos vazios vão como null para não falharem as validações de formato
        const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() === '' ? null : v.trim()]));
        await api.post('/companies', payload);
        router.visit('/perfil');
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
    <Head title="Registar empresa" />

    <h1 class="text-display">Dados da empresa</h1>
    <p class="mt-1 text-ink-muted">Fica como gestor RH. Pode adicionar os funcionários a seguir.</p>

    <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
        <FormField label="Nome da empresa" for="name" :error="errors.name">
            <input id="name" v-model="form.name" class="input" autocomplete="organization" placeholder="Ex.: Cantina Central Talatona" />
        </FormField>
        <FormField label="NIF (opcional)" for="nif" :error="errors.nif">
            <input id="nif" v-model="form.nif" class="input" inputmode="numeric" />
        </FormField>
        <FormField label="Setor (opcional)" for="sector" :error="errors.sector">
            <input id="sector" v-model="form.sector" class="input" placeholder="Ex.: Alimentação" />
        </FormField>
        <div class="grid grid-cols-2 gap-3">
            <FormField label="Município" for="mun" :error="errors.municipality">
                <input id="mun" v-model="form.municipality" class="input" />
            </FormField>
            <FormField label="Bairro" for="bairro" :error="errors.neighborhood">
                <input id="bairro" v-model="form.neighborhood" class="input" />
            </FormField>
        </div>
        <FormField label="Telefone (opcional)" for="phone" :error="errors.phone">
            <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" placeholder="923 456 789" />
        </FormField>
        <FormField label="E-mail (opcional)" for="email" :error="errors.email">
            <input id="email" v-model="form.email" class="input" type="email" inputmode="email" />
        </FormField>
        <p v-if="errors.general" class="text-caption text-danger" role="alert">{{ errors.general }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'A guardar…' : 'Registar empresa' }}</button>
    </form>
</template>
