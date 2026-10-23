<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

// Página de referência visual (só em ambiente local): componentes base alinhados com o kit de telas.
const activeChip = ref('Disciplina: Matemática');
const chips = ['Disciplina: Matemática', 'Distância: 5 km', 'Preço'];
</script>

<template>
    <Head title="Kit de componentes" />

    <h1 class="text-display">Kit de componentes</h1>
    <p class="text-ink-muted">Referência visual para comparar com as telas do kit.</p>

    <section class="mt-6 flex flex-wrap items-center gap-6 rounded-card bg-navy-900 p-4">
        <BrandLogo :size="40" />
        <span class="rounded-control bg-surface p-2"><BrandLogo variant="dark" :size="32" /></span>
        <BrandLogo :size="40" :wordmark="false" />
    </section>

    <h2 class="mt-8 text-title">Botões</h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <button type="button" class="btn-primary">Contratar</button>
        <button type="button" class="btn-navy">Tornar-se Formador</button>
        <button type="button" class="btn-outline">Aceitar</button>
        <button type="button" class="btn-secondary">Salvar rascunho</button>
        <button type="button" class="btn-primary" disabled>Desativado</button>
        <button type="button" class="btn-primary rounded-full">Sou Aluno</button>
    </div>

    <h2 class="mt-8 text-title">Pesquisa e filtros</h2>
    <div class="mt-3 rounded-card bg-navy-900 p-4">
        <label class="search-pill">
            <AppIcon name="search" :size="20" />
            <input type="search" placeholder="O que quer aprender?" class="w-full bg-transparent text-ink outline-none placeholder:text-ink-muted" />
        </label>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button
            v-for="chip in chips"
            :key="chip"
            type="button"
            class="chip"
            :class="{ 'chip-active': activeChip === chip }"
            @click="activeChip = chip"
        >
            {{ chip }}
        </button>
    </div>

    <h2 class="mt-8 text-title">Selos</h2>
    <div class="mt-3 flex flex-wrap gap-2">
        <span class="badge badge-success"><AppIcon name="check-badge" :size="14" /> Verificado LONGA</span>
        <span class="badge badge-warning">Pendente</span>
        <span class="badge badge-neutral">Concluído</span>
        <span class="badge badge-brand">Instrutor · SGME</span>
    </div>

    <h2 class="mt-8 text-title">Campos</h2>
    <div class="mt-3 grid gap-3">
        <label class="grid gap-1">
            <span class="text-caption font-medium text-ink-muted">Telefone</span>
            <input class="input" type="tel" inputmode="tel" placeholder="+244 9XX XXX XXX" />
        </label>
    </div>

    <h2 class="mt-8 text-title">Cartão de formador</h2>
    <article class="card mt-3 flex gap-4">
        <span class="avatar-initials size-16 text-title">ML</span>
        <div class="flex-1">
            <p class="font-semibold">Maria Longi</p>
            <p class="text-caption text-ink-muted">Matemática</p>
            <p class="mt-1 text-caption"><span class="text-orange-500">★</span> 4,9 · 32 aulas</p>
            <p class="mt-1 font-semibold">5.000 Kz / 2h</p>
            <p class="mt-1 flex items-center gap-1 text-caption text-ink-muted">
                <AppIcon name="map-pin" :size="14" /> 1,2 km · Talatona
            </p>
        </div>
    </article>
    <button type="button" class="btn-primary mt-3 w-full">Contratar</button>
</template>
