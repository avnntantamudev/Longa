<script setup lang="ts">
import PushOptIn from '@/components/PushOptIn.vue';
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError } from '@/lib/api';
import { distance, kwanza, when } from '@/lib/format';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Offer {
    id: number;
    title: string;
    category: string;
    provider_net: number;
    amount: number;
    scheduled_at: string;
    neighborhood: string;
    distance_m: number;
    seconds_left: number;
    scheduled: boolean;
    expires_at: string;
}

const page = usePage();
const offers = ref<Offer[]>([]);
const now = computed(() => offers.value.filter((o) => !o.scheduled));
const scheduled = computed(() => offers.value.filter((o) => o.scheduled));
const available = ref<boolean | null>(null);
const availabilityError = ref<string | null>(null);

async function load(): Promise<void> {
    offers.value = (await api.get<{ data: Offer[] }>('/offers')).data;
}

async function loadProfile(): Promise<void> {
    const { data } = await api.get<{ data: { is_available: boolean } }>('/me/provider-profile');
    available.value = data.is_available;
}

async function toggle(): Promise<void> {
    availabilityError.value = null;
    try {
        const { data } = await api.put<{ data: { is_available: boolean } }>('/me/provider-profile/availability', { is_available: !available.value });
        available.value = data.is_available;
    } catch (e) {
        availabilityError.value = e instanceof ApiError ? e.message : 'Não foi possível alterar.';
    }
}

// Nova oferta em tempo real: abre logo o ecrã TRIM TRIM
usePrivateChannel(`user.${page.props.auth.user?.id}`, {
    'offer.sent': (payload: { offer: Offer }) => router.visit(`/tecnico/ofertas/${payload.offer.id}`),
    'offer.expired': () => load(),
    'request.matched': () => load(),
    'request.cancelled': () => load(),
});
usePolling(load, 15000);
onMounted(() => Promise.all([load(), loadProfile()]));
</script>

<template>
    <Head title="Pedidos TRIM TRIM" />

    <section class="rounded-sheet bg-navy-900 p-5 text-white">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-caption font-semibold uppercase tracking-widest text-orange-400">TRIM TRIM</p>
                <p class="mt-1 text-title">{{ available ? 'Está disponível' : 'Está indisponível' }}</p>
                <p class="text-caption text-navy-200">{{ available ? 'Recebe pedidos num raio de 5 km.' : 'Não recebe pedidos novos.' }}</p>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="available === true"
                class="relative h-8 w-14 shrink-0 rounded-full transition-colors"
                :class="available ? 'bg-orange-500' : 'bg-white/20'"
                :disabled="available === null"
                @click="toggle"
            >
                <span class="absolute top-1 size-6 rounded-full bg-white transition-all" :class="available ? 'left-7' : 'left-1'" />
                <span class="sr-only">Disponível</span>
            </button>
        </div>
        <p v-if="availabilityError" class="mt-3 text-caption text-orange-300" role="alert">
            {{ availabilityError }} <Link href="/onboarding/tecnico" class="underline">Completar perfil</Link>
        </p>
    </section>

    <div class="mt-4">
        <PushOptIn title="Receba o TRIM TRIM com a app fechada" description="Os pedidos duram 60 segundos: sem notificações, pode perdê-los." />
    </div>

    <h2 class="mt-6 text-title">Pedidos em aberto</h2>
    <p v-if="now.length === 0" class="card mt-3 text-ink-muted">Sem pedidos neste momento. Quando chegar um, abre aqui automaticamente.</p>
    <ul class="mt-3 grid gap-3">
        <li v-for="offer in now" :key="offer.id">
            <Link :href="`/tecnico/ofertas/${offer.id}`" class="card flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ offer.category }} · {{ kwanza(offer.amount) }}</p>
                    <p class="text-caption text-ink-muted">{{ when(offer.scheduled_at) }} · {{ offer.neighborhood }} · {{ distance(offer.distance_m) }}</p>
                </div>
                <span class="badge badge-brand">{{ offer.seconds_left }}s</span>
            </Link>
        </li>
    </ul>

    <template v-if="scheduled.length">
        <h2 class="mt-6 text-title">Pedidos agendados</h2>
        <p class="mt-1 text-caption text-ink-muted">Serviços para outro dia. O primeiro técnico a aceitar fica com o serviço.</p>
        <ul class="mt-3 grid gap-3">
            <li v-for="offer in scheduled" :key="offer.id">
                <Link :href="`/tecnico/ofertas/${offer.id}`" class="card flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ offer.category }} · {{ kwanza(offer.provider_net) }} para si</p>
                        <p class="text-caption text-ink-muted">{{ when(offer.scheduled_at) }} · {{ offer.neighborhood }} · {{ distance(offer.distance_m) }}</p>
                    </div>
                    <span class="badge badge-neutral shrink-0">até {{ when(offer.expires_at) }}</span>
                </Link>
            </li>
        </ul>
    </template>
</template>
