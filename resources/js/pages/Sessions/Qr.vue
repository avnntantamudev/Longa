<script setup lang="ts">
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError } from '@/lib/api';
import { kwanza } from '@/lib/format';
import { qrPayload, type QrBatch, type QrToken, type Session } from '@/lib/session';
import { Head, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * QR de confirmação (formador). Mostra um lote de tokens em sequência, um por janela de 30 s,
 * com o relógio acertado pelo do servidor. Sem rede continua a mostrar o lote que tem e pede um novo
 * quando faltar menos de um minuto. Os tokens nunca são guardados no dispositivo.
 */
const props = defineProps<{ sessionId: number }>();

const canvas = ref<HTMLCanvasElement | null>(null);
const session = ref<Session | null>(null);
const batch = ref<QrBatch | null>(null);
const offsetMs = ref(0); // relógio do servidor − relógio do telemóvel
const now = ref(Date.now());
const error = ref('');
const confirmed = ref(false);
const fetching = ref(false);
const stopped = ref(false); // erro definitivo do servidor (ex.: janela do QR fechada)
let lastAttempt = 0;
let wakeLock: { release: () => Promise<void> } | null = null;

const serverNow = computed(() => now.value + offsetMs.value);
const current = computed<QrToken | null>(() => {
    const tokens = batch.value?.tokens ?? [];
    return tokens.find((t) => Date.parse(t.valid_from) <= serverNow.value && serverNow.value < Date.parse(t.valid_from) + (batch.value?.rotation_seconds ?? 30) * 1000) ?? null;
});
const secondsLeft = computed(() => {
    if (!current.value || !batch.value) return 0;
    return Math.max(0, Math.ceil((Date.parse(current.value.valid_from) + batch.value.rotation_seconds * 1000 - serverNow.value) / 1000));
});
const batchEndsIn = computed(() => {
    const last = batch.value?.tokens.at(-1);
    return last ? Date.parse(last.valid_from) + (batch.value?.rotation_seconds ?? 30) * 1000 - serverNow.value : 0;
});

async function fetchBatch() {
    if (fetching.value || stopped.value) return;
    fetching.value = true;
    lastAttempt = Date.now();
    try {
        const response = await api.post<{ data: Session; qr: QrBatch }>(`/sessions/${props.sessionId}/qr`);
        session.value = response.data;
        batch.value = response.qr;
        offsetMs.value = Date.parse(response.qr.server_time) - Date.now();
        error.value = '';
    } catch (e) {
        if (e instanceof ApiError) {
            stopped.value = true;
            error.value = e.message;
            await refreshSession().catch(() => undefined);
        } else {
            error.value = 'Sem rede: a mostrar os códigos que já tem.';
        }
    } finally {
        fetching.value = false;
    }
}

async function refreshSession() {
    session.value = (await api.get<{ data: Session }>(`/sessions/${props.sessionId}`)).data;
    if (session.value.state === 'completed') confirmed.value = true;
}

async function draw() {
    await nextTick();
    if (canvas.value && current.value) {
        await QRCode.toCanvas(canvas.value, qrPayload(props.sessionId, current.value.token), {
            errorCorrectionLevel: 'M',
            margin: 2,
            width: Math.min(320, window.innerWidth - 48),
            color: { dark: '#0b1f3a', light: '#ffffff' },
        });
    }
}

watch(() => current.value?.token, draw);

const ticker = setInterval(() => {
    now.value = Date.now();
    // Novo lote antes de o atual acabar (ou já acabou e voltou a rede)
    if (!confirmed.value && batchEndsIn.value < 60_000 && Date.now() - lastAttempt > 10_000) fetchBatch();
}, 500);

usePrivateChannel(`session.${props.sessionId}`, {
    'session.confirmed': () => {
        confirmed.value = true;
        refreshSession();
    },
});
usePolling(() => !confirmed.value && refreshSession().catch(() => undefined), 10000);

onMounted(async () => {
    await fetchBatch();
    try {
        // Manter o ecrã ligado enquanto o aluno lê o QR
        wakeLock = await (navigator as Navigator & { wakeLock?: { request: (t: 'screen') => Promise<{ release: () => Promise<void> }> } }).wakeLock?.request('screen') ?? null;
    } catch {
        wakeLock = null;
    }
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    wakeLock?.release().catch(() => undefined);
});
</script>

<template>
    <Head title="QR da aula" />

    <!-- Confirmada -->
    <section v-if="confirmed" class="flex flex-col items-center gap-4 py-10 text-center" aria-live="assertive">
        <span class="flex size-24 items-center justify-center rounded-full bg-success text-5xl text-white">✓</span>
        <h1 class="text-display">Aula confirmada</h1>
        <p v-if="session?.payment_method === 'cash'" class="text-ink-muted">O aluno confirmou. O pagamento é feito em mão.</p>
        <p v-else class="text-title">{{ kwanza(session?.provider_net ?? 0) }} <span class="text-ink-muted">na sua carteira</span></p>
        <Link href="/carteira" class="btn-primary w-full max-w-xs">Ver carteira</Link>
        <Link :href="`/aulas/${sessionId}`" class="text-caption font-semibold">Voltar à aula</Link>
    </section>

    <!-- QR -->
    <section v-else class="flex flex-col items-center gap-4 text-center">
        <h1 class="text-title">Mostre este QR ao aluno</h1>
        <p class="text-caption text-ink-muted">O aluno abre a aula na app e toca em "Escanear QR para confirmar".</p>

        <div class="rounded-sheet bg-white p-3 shadow-nav" :class="{ 'opacity-30': !current }">
            <canvas ref="canvas" aria-label="QR de confirmação da aula" role="img" />
        </div>

        <template v-if="current">
            <p class="text-caption text-ink-muted">Ou diga o código</p>
            <p class="font-mono text-display tracking-[0.3em]" data-testid="code">{{ current.code.slice(0, 3) }} {{ current.code.slice(3) }}</p>
            <div class="h-2 w-48 overflow-hidden rounded-full bg-navy-50" role="timer" :aria-label="`Muda em ${secondsLeft} segundos`">
                <div class="h-full bg-orange-500 transition-[width] duration-500" :style="{ width: `${(secondsLeft / (batch?.rotation_seconds ?? 30)) * 100}%` }" />
            </div>
            <p class="text-caption text-ink-muted">Muda em {{ secondsLeft }} s</p>
        </template>
        <p v-else class="text-caption text-ink-muted">{{ fetching ? 'A gerar códigos…' : 'Sem códigos válidos. A tentar de novo…' }}</p>

        <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
        <p class="text-caption text-ink-muted">Aumente o brilho do ecrã se o aluno tiver dificuldade em ler.</p>
        <Link :href="`/aulas/${sessionId}`" class="text-caption font-semibold">Voltar à aula</Link>
    </section>
</template>
