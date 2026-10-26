<script setup lang="ts">
import PinPad from '@/components/PinPad.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

/** Entrar só com o PIN (aparelho de confiança) ou desbloquear após 1 hora sem uso. */

const props = defineProps<{ mode: 'login' | 'unlock'; name: string | null; phone: string; photo: string | null }>();

const form = useForm({ pin: '' });

function submit() {
    form.post(props.mode === 'login' ? '/entrar/pin' : '/desbloquear', {
        preserveScroll: true,
        onError: () => form.reset('pin'),
    });
}

function forgot() {
    // Esqueci o PIN: entrar com o código SMS e criar um PIN novo
    if (props.mode === 'unlock') {
        router.post('/sair', {}, { onFinish: () => router.visit('/entrar?pin=esqueci') });
    } else {
        router.visit('/entrar?pin=esqueci');
    }
}
</script>

<template>
    <Head :title="mode === 'login' ? 'Entrar' : 'Desbloquear'" />

    <div class="grid justify-items-center gap-3 text-center">
        <UserAvatar :name="name" :src="photo" :size="72" />
        <h1 class="text-title">Olá{{ name ? `, ${name.split(' ')[0]}` : '' }}</h1>
        <p class="text-caption text-ink-muted">{{ phone }}</p>
        <p class="text-caption">{{ mode === 'login' ? 'Introduza o seu PIN para entrar.' : 'Por segurança, confirme o seu PIN.' }}</p>
    </div>

    <div class="mt-6">
        <PinPad v-model="form.pin" :error="form.errors.pin" :disabled="form.processing" @complete="submit" />
    </div>

    <div class="mt-6 grid gap-2 text-center text-caption font-semibold">
        <button type="button" class="text-orange-600" @click="forgot">Esqueci o PIN</button>
        <button v-if="mode === 'login'" type="button" class="text-ink-muted" @click="router.post('/entrar/outra-conta')">Usar outra conta</button>
        <button v-else type="button" class="text-ink-muted" @click="router.post('/sair')">Sair</button>
    </div>
</template>
