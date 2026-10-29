<script setup lang="ts">
import { hasProvider, type RequestState } from '@/lib/requestState';
import AppIcon from '@/components/AppIcon.vue';
import PushOptIn from '@/components/PushOptIn.vue';
import { useCountdown } from '@/composables/useCountdown';
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ requestId: number }>();

interface ServiceRequest {
    id: number;
    state: RequestState;
    title: string;
    category: { name: string };
    amount: number;
    scheduled_at: string;
    neighborhood: string;
    seconds_left: number | null;
    scheduled: boolean;
    expires_at: string | null;
    wave: number;
    open_offers: number;
    session_id: number | null;
}

const request = ref<ServiceRequest | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const countdown = useCountdown();

async function load(): Promise<void> {
    const { data } = await api.get<{ data: ServiceRequest }>(`/service-requests/${props.requestId}`);
    request.value = data;
    if (data.state === 'searching' && data.seconds_left !== null) {
        countdown.start(data.seconds_left);
    }
    if (hasProvider(data.state) && data.session_id) {
        router.visit(`/servicos/${data.session_id}`);
    }
}

// Tempo real (Reverb); o polling cobre ligações instáveis
usePrivateChannel(`request.${props.requestId}`, {
    'request.matched': () => load(),
    'request.expired': () => load(),
    'request.cancelled': () => load(),
});
usePolling(() => request.value?.state === 'searching' && load(), 8000);
onMounted(load);

const minutesLeft = computed(() => `${Math.floor(countdown.secondsLeft.value / 60)}:${String(countdown.secondsLeft.value % 60).padStart(2, '0')}`);

async function cancel(): Promise<void> {
    if (!confirm('Cancelar este pedido?')) {
        return;
    }
    busy.value = true;
    try {
        await api.post(`/service-requests/${props.requestId}/cancel`);
        await load();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível cancelar.';
        await load();
    } finally {
        busy.value = false;
    }
}

async function republish(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: { id: number } }>(`/service-requests/${props.requestId}/republish`);
        router.visit(`/pedidos/${data.id}`);
    } catch (e) {
        if (e instanceof ApiError) {
            error.value = e.fieldErrors().scheduled_at ?? e.message;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Pedido de serviço" />

    <template v-if="request">
        <!-- À procura -->
        <section v-if="request.state === 'searching'" class="rounded-sheet bg-navy-900 px-5 py-8 text-center text-white">
            <div class="relative mx-auto flex size-28 items-center justify-center">
                <span class="absolute inset-0 animate-ping rounded-full bg-orange-500/30" />
                <span class="absolute inset-3 rounded-full bg-orange-500/20" />
                <span class="relative inline-flex size-16 items-center justify-center rounded-full bg-orange-500"><AppIcon name="search" :size="30" /></span>
            </div>
            <h1 class="mt-6 text-title">{{ request.scheduled ? 'Pedido enviado aos técnicos' : 'A procurar técnicos perto de si…' }}</h1>
            <p class="mt-1 text-navy-200">
                {{ request.open_offers > 0 ? `${request.open_offers} técnico(es) a ver o seu pedido agora` : 'A preparar o próximo envio' }}
            </p>
            <p v-if="request.scheduled" class="mt-4 text-caption text-navy-300">
                Serviço noutro dia: técnicos perto de si e técnicos VIP da província podem aceitar até {{ request.expires_at ? when(request.expires_at) : '' }}. Avisamos quando alguém aceitar.
            </p>
            <p v-else class="mt-4 text-caption text-navy-300">Tempo restante {{ minutesLeft }} · envio {{ request.wave }}</p>
        </section>

        <!-- Sem técnicos -->
        <section v-else-if="request.state === 'no_provider'" class="card text-center">
            <p class="text-title">Sem técnicos disponíveis</p>
            <p class="mt-1 text-ink-muted">Ninguém aceitou a tempo. Pode republicar, por exemplo com outro valor ou outra hora.</p>
            <button type="button" class="btn-primary mt-4 w-full" :disabled="busy" @click="republish">Republicar pedido</button>
            <a href="/pedidos/novo" class="btn-secondary mt-2 w-full">Novo pedido com outros dados</a>
        </section>

        <section v-else-if="request.state === 'cancelled'" class="card text-center">
            <p class="text-title">Pedido cancelado</p>
            <button type="button" class="btn-secondary mt-4 w-full" :disabled="busy" @click="republish">Publicar de novo</button>
        </section>

        <section v-else-if="hasProvider(request.state)" class="card text-center">
            <p class="text-title text-success">Técnico encontrado!</p>
            <a v-if="request.session_id" :href="`/servicos/${request.session_id}`" class="btn-primary mt-4 w-full">Ver serviço</a>
        </section>

        <!-- Resumo -->
        <section class="card mt-4">
            <p class="font-semibold">{{ request.title }}</p>
            <p class="text-caption text-ink-muted">{{ request.category.name }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-caption">
                <div><dt class="text-ink-muted">Quando</dt><dd class="font-semibold">{{ when(request.scheduled_at) }}</dd></div>
                <div><dt class="text-ink-muted">Valor</dt><dd class="font-semibold">{{ kwanza(request.amount) }}</dd></div>
                <div><dt class="text-ink-muted">Local</dt><dd class="font-semibold">{{ request.neighborhood }}</dd></div>
            </dl>
        </section>

        <p v-if="error" class="mt-3 text-caption text-danger" role="alert">{{ error }}</p>

        <button v-if="request.state === 'searching'" type="button" class="btn-secondary mt-4 w-full" :disabled="busy" @click="cancel">Cancelar pedido</button>

        <div class="mt-4">
            <PushOptIn title="Quer ser avisado quando um técnico aceitar?" description="Receba o aviso mesmo com a app fechada." />
        </div>
    </template>

    <p v-else class="text-ink-muted">A carregar…</p>
</template>
