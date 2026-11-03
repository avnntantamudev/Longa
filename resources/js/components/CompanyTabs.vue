<script setup lang="ts">
/** Separadores da área da empresa (RH): gerir funcionários e ver relatórios. */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const tabs = [
    { label: 'Funcionários', href: '/empresa/equipa' },
    { label: 'Relatórios', href: '/empresa/relatorios' },
];
const path = computed(() => page.url.split('?')[0]);
</script>

<template>
    <nav class="mb-4 flex gap-2" aria-label="Área da empresa">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            class="chip"
            :class="{ 'chip-active': path === tab.href }"
            :aria-current="path === tab.href ? 'page' : undefined"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
