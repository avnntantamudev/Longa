<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ profiles: Array<{ key: string; title: string; description: string }> }>();
</script>

<template>
    <Head title="Início" />

    <section class="rounded-sheet bg-navy-900 px-5 py-8 text-white">
        <p class="text-caption font-semibold uppercase tracking-widest text-orange-400">Bem-vindo ao LONGA</p>
        <h1 class="mt-2 text-display">Formação perto de si</h1>
        <p class="mt-2 text-navy-100">Encontre formadores verificados, marque aulas e receba certificados.</p>
    </section>

    <h2 class="mt-8 text-title">Como quer usar o LONGA?</h2>
    <ul class="mt-4 grid gap-3 sm:grid-cols-3">
        <li v-for="profile in profiles" :key="profile.key" class="card">
            <p class="font-semibold">{{ profile.title }}</p>
            <p class="mt-1 text-caption text-ink-muted">{{ profile.description }}</p>
        </li>
    </ul>

    <button type="button" class="btn-primary mt-6 w-full" disabled>Entrar com o telemóvel (brevemente)</button>
</template>
