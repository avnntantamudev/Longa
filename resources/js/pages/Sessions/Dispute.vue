<script setup lang="ts">
import FormField from '@/components/FormField.vue';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Abrir disputa: o aluno durante a confirmação, ou a parte acusada de faltar (contestação).
 * Evidências: até 5 imagens ou PDF de 5 MB (validado também no servidor, pelo conteúdo).
 */
const props = defineProps<{ sessionId: number; contest: boolean }>();

const MAX_FILES = 5;
const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

const reasons = computed(() =>
    props.contest
        ? [{ value: 'no_show_contest', label: 'Eu estive presente / a falta não é verdade' }]
        : [
              { value: 'lesson_not_happened', label: 'A aula não aconteceu' },
              { value: 'quality', label: 'Problema com a aula' },
              { value: 'other', label: 'Outro motivo' },
          ],
);
const reason = ref(reasons.value[0]!.value);
const description = ref('');
const files = ref<File[]>([]);
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const key = newIdempotencyKey();

function onFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    const rejected = picked.filter((f) => !ACCEPT.includes(f.type) || f.size > MAX_BYTES);
    files.value = [...files.value, ...picked.filter((f) => !rejected.includes(f))].slice(0, MAX_FILES);
    errors.value.attachments = rejected.length ? 'Só imagens (JPG, PNG, WebP) ou PDF até 5 MB.' : '';
    input.value = '';
}

async function submit() {
    errors.value = {};
    if (description.value.trim().length < 10) {
        errors.value.description = 'Descreva o que aconteceu (pelo menos 10 caracteres).';
        return;
    }
    const body = new FormData();
    body.append('reason', reason.value);
    body.append('description', description.value.trim());
    files.value.forEach((f) => body.append('attachments[]', f));

    saving.value = true;
    try {
        await api.post(`/sessions/${props.sessionId}/disputes`, body, { idempotencyKey: key });
        router.visit(`/aulas/${props.sessionId}`);
    } catch (e) {
        if (e instanceof ApiError) {
            errors.value = e.fieldErrors();
            if (!Object.keys(errors.value).length) errors.value.form = e.message;
        } else {
            errors.value.form = 'Sem ligação. Tente de novo.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="contest ? 'Contestar falta' : 'Abrir disputa'" />

    <h1 class="text-display">{{ contest ? 'Contestar falta' : 'Abrir disputa' }}</h1>
    <p class="mt-2 text-caption text-ink-muted">A LONGA analisa e decide. Até lá, o valor da aula fica retido e ninguém o recebe.</p>

    <form class="mt-6 grid gap-4" @submit.prevent="submit">
        <FormField label="Motivo" for="reason" :error="errors.reason">
            <select id="reason" v-model="reason" class="input">
                <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
        </FormField>

        <FormField label="O que aconteceu?" for="description" :error="errors.description">
            <textarea id="description" v-model="description" class="input min-h-32 py-3" maxlength="2000" />
        </FormField>

        <FormField label="Evidências (opcional)" for="files" :error="errors.attachments || errors['attachments.0']" hint="Fotos ou PDF, até 5 ficheiros de 5 MB.">
            <input id="files" type="file" class="input py-2" multiple accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="files.length >= MAX_FILES" @change="onFiles" />
        </FormField>
        <ul v-if="files.length" class="grid gap-1 text-caption">
            <li v-for="(f, i) in files" :key="i" class="flex items-center justify-between rounded-control bg-navy-50 px-3 py-2">
                <span class="truncate">{{ f.name }}</span>
                <button type="button" class="font-semibold text-danger" @click="files.splice(i, 1)">Remover</button>
            </li>
        </ul>

        <p v-if="errors.form" class="text-caption text-danger" role="alert">{{ errors.form }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'A enviar…' : 'Enviar' }}</button>
        <Link :href="`/aulas/${sessionId}`" class="text-center text-caption font-semibold">Voltar</Link>
    </form>
</template>
