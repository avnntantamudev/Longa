<script setup lang="ts">
import LegalDialog, { type LegalText } from '@/components/LegalDialog.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/** Nova versão dos Termos ou da Política de Privacidade: aceitar para continuar a usar o BISCA. */
const props = defineProps<{ documents: Array<{ document: LegalText['document']; slug: string; title: string; version: string }> }>();

const checked = reactive<Record<string, boolean>>(Object.fromEntries(props.documents.map((d) => [d.document, false])));
const reading = ref<LegalText['document'] | null>(null);
const form = useForm({ documents: [] as string[] });
const allChecked = computed(() => props.documents.every((d) => checked[d.document]));

function submit() {
    form.documents = props.documents.filter((d) => checked[d.document]).map((d) => d.document);
    form.post('/termos/aceitar');
}
</script>

<template>
    <Head title="Atualizámos os nossos termos" />

    <h1 class="text-display">Atualizámos os nossos termos</h1>
    <p class="mt-1 text-ink-muted">Para continuar a usar o BISCA, leia e aceite a nova versão.</p>

    <form class="mt-6 grid gap-4" @submit.prevent="submit">
        <label v-for="doc in documents" :key="doc.document" class="flex items-start gap-3 text-caption">
            <input v-model="checked[doc.document]" type="checkbox" class="mt-0.5 size-5 accent-orange-500" />
            <span>
                Li e aceito a nova versão ({{ doc.version }}) d{{ doc.document === 'terms' ? 'os' : 'a' }}
                <button type="button" class="font-semibold text-orange-600 underline" @click="reading = doc.document">{{ doc.title }}</button>.
            </span>
        </label>
        <p v-if="form.errors.documents" class="text-caption text-danger" role="alert">{{ form.errors.documents }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="!allChecked || form.processing">Aceitar e continuar</button>
    </form>

    <LegalDialog :document="reading" @close="reading = null" />
</template>
