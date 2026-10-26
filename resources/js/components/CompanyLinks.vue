<script setup lang="ts">
import { api, ApiError } from '@/lib/api';
import { onMounted, ref } from 'vue';

/** Pedidos de empresas para acompanhar as formações do utilizador (aceitar, recusar, retirar). */
interface Link {
    id: number;
    company: string;
    department: string | null;
    consent_status: 'pending' | 'accepted' | 'declined' | 'revoked';
}

const links = ref<Link[]>([]);
const error = ref('');

async function load() {
    links.value = (await api.get<{ data: Link[] }>('/me/company-links')).data;
}

async function decide(link: Link, decision: 'accepted' | 'declined' | 'revoked') {
    error.value = '';
    try {
        await api.post(`/me/company-links/${link.id}`, { decision });
        await load();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    }
}

onMounted(load);
</script>

<template>
    <section v-if="links.length" class="card mt-4 grid gap-3">
        <h2 class="font-semibold">Empresas</h2>
        <div v-for="link in links" :key="link.id" class="grid gap-2 rounded-control bg-navy-50 p-3">
            <p class="text-caption">
                <strong>{{ link.company }}</strong>
                <template v-if="link.consent_status === 'pending'"> quer acompanhar as suas formações na LONGA: aulas concluídas, horas e custos (nunca o chat).</template>
                <template v-else-if="link.consent_status === 'accepted'"> acompanha as suas formações.</template>
                <template v-else> não tem acesso às suas formações.</template>
            </p>
            <div class="flex gap-2">
                <template v-if="link.consent_status === 'pending' || link.consent_status === 'declined' || link.consent_status === 'revoked'">
                    <button class="btn-primary min-h-10 flex-1 px-3 text-caption" @click="decide(link, 'accepted')">Autorizar</button>
                    <button v-if="link.consent_status === 'pending'" class="btn-outline min-h-10 flex-1 px-3 text-caption" @click="decide(link, 'declined')">Recusar</button>
                </template>
                <button v-else class="btn-outline min-h-10 flex-1 px-3 text-caption" @click="decide(link, 'revoked')">Retirar autorização</button>
            </div>
        </div>
        <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
    </section>
</template>
