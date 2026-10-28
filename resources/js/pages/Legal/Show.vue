<script setup lang="ts">
import type { LegalText } from '@/components/LegalDialog.vue';
import { Head } from '@inertiajs/vue3';

/** Texto completo dos Termos ou da Política de Privacidade (página própria, também sem sessão). */
defineProps<{ document: LegalText }>();
</script>

<template>
    <Head :title="document.title" />

    <p class="text-caption text-ink-muted">Versão {{ document.version }}</p>
    <!-- eslint-disable-next-line vue/no-v-html -- texto do servidor, gerado a partir de Markdown sem HTML -->
    <article class="legal-text mt-2" v-html="document.html" />
</template>
