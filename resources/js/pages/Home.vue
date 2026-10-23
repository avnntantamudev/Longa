<script setup lang="ts">
import AppIcon, { type IconName } from '@/components/AppIcon.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

defineProps<{
    profiles: Array<{ key: string; title: string; description: string; icon: IconName }>;
    highlights: Array<{ title: string; description: string; icon: IconName }>;
}>();
</script>

<template>
    <Head title="Início" />

    <section class="rounded-sheet bg-navy-900 px-5 py-8 text-white md:px-8 md:py-10">
        <p class="text-caption font-semibold uppercase tracking-widest text-orange-400">Kulonga = Ensinar</p>
        <h1 class="mt-2 text-display md:text-4xl md:leading-tight">A plataforma que formaliza quem ensina em Angola</h1>
        <p class="mt-3 text-navy-100">De Longi para Longi: formadores verificados perto de si, pagamento seguro e certificado no fim.</p>

        <div class="mt-6 grid grid-cols-2 gap-3 sm:max-w-md">
            <template v-if="!user">
                <Link href="/entrar" class="btn-primary rounded-full px-3 whitespace-nowrap">Sou Aluno</Link>
                <Link href="/entrar" class="btn-navy rounded-full bg-navy-800 px-3 whitespace-nowrap ring-1 ring-white/25">Sou Formador</Link>
            </template>
            <template v-else>
                <Link v-if="user.roles.includes('student')" href="/pedidos/novo" class="btn-primary rounded-full px-3 whitespace-nowrap">Pedir aula</Link>
                <Link v-if="user.roles.includes('trainer')" href="/formador/ofertas" class="btn-navy rounded-full bg-navy-800 px-3 whitespace-nowrap ring-1 ring-white/25">TRIM TRIM</Link>
                <Link v-if="user.roles.length === 0" href="/onboarding" class="btn-primary rounded-full px-3 whitespace-nowrap">Concluir registo</Link>
            </template>
        </div>
    </section>

    <h2 class="mt-8 text-title">Porquê a LONGA?</h2>
    <ul class="mt-4 grid gap-3 sm:grid-cols-3">
        <li v-for="profile in profiles" :key="profile.key" class="card flex gap-3 sm:flex-col">
            <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-600">
                <AppIcon :name="profile.icon" />
            </span>
            <div>
                <p class="font-semibold">{{ profile.title }}</p>
                <p class="mt-1 text-caption text-ink-muted">{{ profile.description }}</p>
            </div>
        </li>
    </ul>

    <ul class="mt-8 grid gap-4">
        <li v-for="item in highlights" :key="item.title" class="flex items-start gap-3">
            <span class="mt-0.5 text-orange-500"><AppIcon :name="item.icon" /></span>
            <div>
                <p class="font-semibold">{{ item.title }}</p>
                <p class="text-caption text-ink-muted">{{ item.description }}</p>
            </div>
        </li>
    </ul>
</template>
