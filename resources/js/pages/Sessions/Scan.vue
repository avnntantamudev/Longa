<script setup lang="ts">
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { kwanza } from '@/lib/format';
import { parseQrPayload, type Session } from '@/lib/session';
import { Head, Link } from '@inertiajs/vue3';
import jsQR from 'jsqr';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Confirmar o serviço (cliente): lê o QR com a câmara (BarcodeDetector nativo; jsQR como alternativa)
 * ou aceita o código de 6 dígitos. A localização é opcional e serve só como sinal de risco.
 */
const props = defineProps<{ sessionId: number }>();

type Phase = 'scanning' | 'sending' | 'done' | 'no_camera';

const video = ref<HTMLVideoElement | null>(null);
const phase = ref<Phase>('scanning');
const error = ref('');
const code = ref('');
const session = ref<Session | null>(null);
const message = ref('');
let stream: MediaStream | null = null;
let frame = 0;
let key = newIdempotencyKey();
let coords: { latitude: number; longitude: number } | null = null;

interface Detector {
    detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
}
const NativeDetector = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
const detector: Detector | null = NativeDetector ? new NativeDetector({ formats: ['qr_code'] }) : null;
const scratch = document.createElement('canvas');

async function startCamera() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
            scan();
        }
    } catch {
        phase.value = 'no_camera';
    }
}

function stopCamera() {
    cancelAnimationFrame(frame);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

async function readFrame(): Promise<string | null> {
    const v = video.value;
    if (!v || v.readyState < 2) return null;
    if (detector) {
        const found = await detector.detect(v).catch(() => []);
        return found[0]?.rawValue ?? null;
    }
    scratch.width = v.videoWidth;
    scratch.height = v.videoHeight;
    const ctx = scratch.getContext('2d', { willReadFrequently: true });
    if (!ctx) return null;
    ctx.drawImage(v, 0, 0);
    const image = ctx.getImageData(0, 0, scratch.width, scratch.height);
    return jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
}

async function scan() {
    if (phase.value !== 'scanning') return;
    const raw = await readFrame();
    if (raw) {
        const parsed = parseQrPayload(raw);
        if (!parsed) {
            error.value = 'Este QR não é de um serviço do BISCA.';
        } else if (parsed.sessionId !== props.sessionId) {
            error.value = 'Este QR é de outro serviço.';
        } else {
            await send({ token: parsed.token });
            return;
        }
    }
    frame = requestAnimationFrame(() => setTimeout(scan, 150));
}

async function send(body: { token?: string; code?: string }) {
    phase.value = 'sending';
    error.value = '';
    try {
        const response = await api.post<{ data: Session; message: string }>(
            `/sessions/${props.sessionId}/confirm`,
            { ...body, ...(coords ?? {}) },
            { idempotencyKey: key },
        );
        session.value = response.data;
        message.value = response.message;
        phase.value = 'done';
        stopCamera();
    } catch (e) {
        if (e instanceof ApiError) {
            key = newIdempotencyKey();
            error.value = e.message;
        } else {
            error.value = 'Sem ligação. Tente de novo.'; // mantém a chave: a repetição não duplica
        }
        phase.value = stream ? 'scanning' : 'no_camera';
        if (stream) frame = requestAnimationFrame(() => setTimeout(scan, 1000));
    }
}

function sendCode() {
    if (/^\d{6}$/.test(code.value.replace(/\s/g, ''))) {
        send({ code: code.value.replace(/\s/g, '') });
    } else {
        error.value = 'O código tem 6 dígitos.';
    }
}

onMounted(() => {
    startCamera();
    navigator.geolocation?.getCurrentPosition(
        (p) => (coords = { latitude: p.coords.latitude, longitude: p.coords.longitude }),
        () => (coords = null),
        { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 },
    );
});
onBeforeUnmount(stopCamera);
</script>

<template>
    <Head title="Confirmar serviço" />

    <section v-if="phase === 'done'" class="flex flex-col items-center gap-4 py-10 text-center" aria-live="assertive">
        <span class="flex size-24 items-center justify-center rounded-full bg-success text-5xl text-white">✓</span>
        <h1 class="text-display">Serviço confirmado</h1>
        <p class="text-ink-muted">{{ message }}</p>
        <p v-if="session && session.payment_method === 'electronic'" class="text-caption text-ink-muted">Pagou {{ kwanza(session.amount) }} pelo BISCA.</p>
        <Link :href="`/servicos/${sessionId}`" class="btn-primary w-full max-w-xs">Voltar ao serviço</Link>
    </section>

    <section v-else class="grid gap-4">
        <h1 class="text-title">Escanear QR para confirmar</h1>
        <p class="text-caption text-ink-muted">Aponte a câmara para o QR no telemóvel do técnico.</p>

        <div v-if="phase !== 'no_camera'" class="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-sheet bg-navy-900">
            <video ref="video" class="size-full object-cover" playsinline muted />
            <div class="pointer-events-none absolute inset-10 rounded-control border-4 border-orange-500/80" />
            <p v-if="phase === 'sending'" class="absolute inset-x-0 bottom-3 text-center text-caption font-semibold text-white">A confirmar…</p>
        </div>
        <p v-else class="card text-caption text-ink-muted">Não foi possível usar a câmara. Peça ao técnico o código de 6 dígitos.</p>

        <p v-if="error" class="rounded-control bg-danger/10 p-3 text-caption font-semibold text-danger" role="alert">{{ error }}</p>

        <form class="card grid gap-2" @submit.prevent="sendCode">
            <label for="code" class="text-caption font-semibold">Ou escreva o código</label>
            <input id="code" v-model="code" class="input text-center font-mono text-title tracking-[0.3em]" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000 000" />
            <button type="submit" class="btn-primary" :disabled="phase === 'sending'">Confirmar</button>
        </form>

        <Link :href="`/servicos/${sessionId}`" class="text-center text-caption font-semibold">Voltar</Link>
    </section>
</template>
