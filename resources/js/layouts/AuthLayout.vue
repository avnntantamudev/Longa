<script setup lang="ts">
import BrandLogo from '@/components/BrandLogo.vue';
import NetworkStatus from '@/components/NetworkStatus.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <!-- Login e registo (kit: painel de marca à esquerda no desktop, cartão branco ao centro) -->
    <div class="min-h-dvh md:grid md:grid-cols-2">
        <aside class="hidden flex-col justify-between bg-navy-900 p-10 text-white md:flex">
            <Link href="/" aria-label="Início"><BrandLogo :size="44" /></Link>
            <div>
                <p class="text-caption font-semibold uppercase tracking-widest text-orange-400">Kulonga = Ensinar</p>
                <p class="mt-3 text-4xl font-bold leading-tight">A plataforma que formaliza quem ensina em Angola.</p>
                <p class="mt-3 text-navy-200">De Longi para Longi.</p>
            </div>
            <p class="text-caption text-navy-300">© LONGA · Luanda, Angola</p>
        </aside>

        <main class="pt-safe flex min-h-dvh flex-col px-4 py-6 md:items-center md:justify-center md:px-10">
            <Link href="/" class="mb-8 md:hidden" aria-label="Início"><BrandLogo variant="dark" :size="34" /></Link>
            <div class="w-full md:max-w-md">
                <slot />
            </div>
        </main>

        <NetworkStatus />
    </div>
</template>
