<script setup lang="ts">
import { api, ApiError } from '@/lib/api';
import { nextTick, ref, watch } from 'vue';

/**
 * Texto completo dos Termos ou da Política de Privacidade numa janela, para ler antes de marcar a caixa.
 * O HTML vem dos ficheiros versionados do servidor (resources/legal), sem HTML embutido.
 */
export interface LegalText {
    document: 'terms' | 'privacy';
    title: string;
    version: string;
    html: string;
}

const props = defineProps<{ document: LegalText['document'] | null }>();
const emit = defineEmits<{ close: [] }>();

const text = ref<LegalText | null>(null);
const error = ref<string | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

watch(
    () => props.document,
    async (doc) => {
        text.value = null;
        error.value = null;
        if (doc === null) return;
        await nextTick();
        closeButton.value?.focus();
        try {
            text.value = (await api.get<{ data: LegalText }>(`/legal/${doc}`)).data;
        } catch (e) {
            error.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
        }
    },
    { immediate: true },
);
</script>

<template>
    <div
        v-if="document"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 md:items-center md:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="legal-dialog-title"
        @keydown.esc="emit('close')"
    >
        <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-t-sheet bg-surface shadow-nav md:rounded-sheet">
            <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="legal-dialog-title" class="text-title">{{ text?.title ?? 'A carregar…' }}</h2>
                    <p v-if="text" class="text-caption text-ink-muted">Versão {{ text.version }}</p>
                </div>
                <button ref="closeButton" type="button" class="btn-secondary px-4" @click="emit('close')">Fechar</button>
            </div>
            <div class="overflow-y-auto px-5 py-4">
                <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
                <!-- eslint-disable-next-line vue/no-v-html -- texto do servidor, gerado a partir de Markdown sem HTML -->
                <article v-else-if="text" class="legal-text" v-html="text.html" />
                <p v-else class="text-caption text-ink-muted">A carregar o texto…</p>
            </div>
        </div>
    </div>
</template>
