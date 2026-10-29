<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import CountdownRing from '@/components/CountdownRing.vue';
import MiniMap from '@/components/MiniMap.vue';
import { useCountdown } from '@/composables/useCountdown';
import { usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError } from '@/lib/api';
import { distance, kwanza, when } from '@/lib/format';
import type { Zone } from '@/types/global';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ offerId: number; zones: Zone[] }>();

interface Offer {
    id: number;
    state: 'sent' | 'viewed' | 'accepted' | 'declined' | 'expired' | 'lost';
    title: string;
    description: string | null;
    category: string;
    pricing_mode: 'fixed' | 'quote' | 'hourly_package';
    answers: Array<{ key: string; label: string; value: string | number }>;
    media: Array<{ id: number; field_key: string; kind: 'photo' | 'video' }>;
    amount: number;
    commission: number;
    provider_net: number;
    scheduled_at: string;
    modality: string;
    municipality: string;
    neighborhood: string;
    distance_m: number;
    seconds_left: number;
    scheduled: boolean;
    expires_at: string;
    session_id?: number | null;
}

const page = usePage();
const offer = ref<Offer | null>(null);
const busy = ref(false);
const outcome = ref<string | null>(null);
const countdown = useCountdown();

/** Abre uma foto ou vídeo do pedido (link temporário) noutro separador */
async function openMedia(id: number): Promise<void> {
    try {
        const { data } = await api.get<{ data: { url: string } }>(`/request-media/${id}`);
        window.open(data.url, '_blank', 'noopener');
    } catch (e) {
        outcome.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    }
}

const isOpen = computed(() => offer.value && ['sent', 'viewed'].includes(offer.value.state) && !countdown.finished.value && !outcome.value);
const zone = computed(() => props.zones.find((z) => z.neighborhood === offer.value?.neighborhood && z.municipality === offer.value?.municipality));

const closedMessage = computed(() => {
    if (outcome.value) {
        return outcome.value;
    }
    switch (offer.value?.state) {
        case 'lost':
            return 'Outro técnico aceitou primeiro (ou o pedido terminou).';
        case 'declined':
            return 'Recusou este pedido.';
        case 'expired':
            return 'O tempo para aceitar terminou.';
        default:
            return countdown.finished.value ? 'O tempo para aceitar terminou.' : null;
    }
});

async function load(): Promise<void> {
    const { data } = await api.post<{ data: Offer }>(`/offers/${props.offerId}/view`);
    offer.value = data;
    countdown.start(data.seconds_left);
    if (data.state === 'accepted' && data.session_id) {
        router.visit(`/servicos/${data.session_id}`);
    }
}

async function accept(): Promise<void> {
    busy.value = true;
    try {
        // Seguro repetir (idempotente no servidor): o "tentar de novo" de rede não duplica o serviço
        const { data } = await api.post<{ data: { id: number } }>(`/offers/${props.offerId}/accept`);
        router.visit(`/servicos/${data.id}`);
    } catch (e) {
        if (e instanceof ApiError) {
            outcome.value = e.message;
        }
    } finally {
        busy.value = false;
    }
}

async function decline(): Promise<void> {
    busy.value = true;
    try {
        await api.post(`/offers/${props.offerId}/decline`);
        router.visit('/tecnico/ofertas');
    } catch (e) {
        if (e instanceof ApiError) {
            outcome.value = e.message;
        }
    } finally {
        busy.value = false;
    }
}

usePrivateChannel(`user.${page.props.auth.user?.id}`, {
    'offer.expired': (payload: { offer_id: number }) => payload.offer_id === props.offerId && load(),
    'request.matched': () => load(),
    'request.cancelled': () => load(),
});

onMounted(() => {
    // "TRIM TRIM": vibra ao abrir uma oferta nova (se o telemóvel permitir)
    navigator.vibrate?.([200, 100, 200, 100, 400]);
    void load();
});
</script>

<template>
    <Head title="TRIM TRIM" />

    <section class="rounded-sheet bg-navy-900 p-5 text-white">
        <p class="flex items-center justify-center gap-2 text-center font-bold text-orange-400">
            <AppIcon name="bell" :size="20" />
            TRIM TRIM · Novo pedido{{ offer ? ` a ${distance(offer.distance_m)}` : '' }}
        </p>

        <template v-if="offer">
            <div class="mt-5 rounded-card bg-white/5 p-4">
                <p class="text-title">Pedido recebido</p>
                <p class="mt-1 font-semibold">{{ offer.title }}</p>
                <p class="text-caption text-navy-200">{{ offer.category }}</p>
                <template v-if="offer.pricing_mode === 'quote'">
                    <p class="mt-3 text-lg font-bold text-orange-400">Por orçamento</p>
                    <p class="text-caption text-navy-200">Vê o problema no local e envia o orçamento na app.</p>
                </template>
                <template v-else>
                    <p class="mt-3 text-2xl font-bold text-orange-400">{{ kwanza(offer.amount) }}</p>
                    <p class="text-caption text-navy-200">Recebe {{ kwanza(offer.provider_net) }} (comissão do BISCA {{ kwanza(offer.commission) }})</p>
                </template>
                <p class="mt-3 flex items-center gap-2 text-caption">
                    <AppIcon name="calendar" :size="16" /> {{ when(offer.scheduled_at) }} · {{ offer.modality === 'online' ? 'Online' : offer.neighborhood }}
                </p>
                <p v-if="offer.description" class="mt-2 text-caption text-navy-100">{{ offer.description }}</p>
                <dl v-if="offer.answers.length" class="mt-3 grid gap-1 text-caption">
                    <div v-for="answer in offer.answers" :key="answer.key">
                        <dt class="text-navy-200">{{ answer.label }}</dt>
                        <dd>{{ answer.value }}</dd>
                    </div>
                </dl>
                <div v-if="offer.media.length" class="mt-3 flex flex-wrap gap-2">
                    <button v-for="(item, i) in offer.media" :key="item.id" type="button" class="chip" @click="openMedia(item.id)">
                        {{ item.kind === 'video' ? 'Vídeo' : `Foto ${i + 1}` }}
                    </button>
                </div>
            </div>

            <div class="mt-5 flex justify-center">
                <CountdownRing v-if="isOpen && !offer.scheduled" :seconds="countdown.secondsLeft.value" />
                <p v-else-if="isOpen" class="rounded-full bg-white/10 px-4 py-2 text-caption">Pedido agendado · aceite até {{ when(offer.expires_at) }}</p>
            </div>

            <div v-if="zone && offer.modality !== 'online'" class="mt-4">
                <p class="mb-2 text-caption text-navy-200">Localização aproximada (o local exato aparece depois de aceitar)</p>
                <MiniMap :lat="zone.lat" :lng="zone.lng" approximate :label="offer.neighborhood" />
            </div>

            <template v-if="isOpen">
                <button type="button" class="btn-primary mt-5 min-h-14 w-full text-lg" :disabled="busy" @click="accept">
                    {{ busy ? 'A aceitar…' : 'Aceitar agora' }}
                </button>
                <button type="button" class="mt-3 min-h-12 w-full rounded-control border border-orange-500/60 font-semibold text-orange-400" :disabled="busy" @click="decline">
                    Recusar
                </button>
                <p class="mt-3 text-center text-caption text-navy-300">Só um técnico fica com o serviço: o primeiro a aceitar.</p>
            </template>

            <div v-else class="mt-5 rounded-card bg-white/10 p-4 text-center" role="status">
                <p class="font-semibold">{{ closedMessage }}</p>
                <Link href="/tecnico/ofertas" class="btn-primary mt-3 w-full">Ver outros pedidos</Link>
            </div>
        </template>

        <p v-else class="mt-6 text-center text-navy-200">A carregar…</p>
    </section>
</template>
