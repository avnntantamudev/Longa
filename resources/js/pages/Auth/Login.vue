<script setup lang="ts">
import FormField from '@/components/FormField.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ phone: string | null; codeLength: number; resendIn: number }>();

const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ phone: props.phone ?? '', code: '' });

const step = computed(() => (props.phone ? 'code' : 'phone'));
const countdown = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

function startCountdown(): void {
    countdown.value = props.resendIn;
    clearInterval(timer);
    timer = setInterval(() => {
        countdown.value = Math.max(0, countdown.value - 1);
        if (countdown.value === 0) {
            clearInterval(timer);
        }
    }, 1000);
}

watch(
    () => props.phone,
    (phone) => {
        codeForm.phone = phone ?? '';
        codeForm.code = '';
        if (phone) {
            startCountdown();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(timer));

function sendCode(): void {
    phoneForm.post('/entrar/codigo', { preserveScroll: true });
}

function resend(): void {
    router.post('/entrar/codigo', { phone: props.phone }, { preserveScroll: true, onSuccess: startCountdown });
}

function verify(): void {
    codeForm.post('/entrar/verificar');
}

// Submete automaticamente quando o código fica completo (inclui preenchimento automático do SMS)
watch(
    () => codeForm.code,
    (code) => {
        if (code.length === props.codeLength && !codeForm.processing) {
            verify();
        }
    },
);

// +244923456789 → +244 9•• ••• 789
const maskedPhone = computed(() => (props.phone ? `${props.phone.slice(0, 4)} ${props.phone.slice(4, 5)}•• ••• ${props.phone.slice(-3)}` : ''));
</script>

<template>
    <Head title="Entrar" />

    <section class="card p-6">
        <template v-if="step === 'phone'">
            <h1 class="text-title">Entrar ou criar conta</h1>
            <p class="mt-1 text-caption text-ink-muted">Enviamos um código por SMS. Não precisa de palavra-passe.</p>

            <form class="mt-6 grid gap-4" novalidate @submit.prevent="sendCode">
                <FormField label="Número de telemóvel" for="phone" :error="phoneForm.errors.phone">
                    <div class="flex">
                        <span class="inline-flex min-h-12 items-center rounded-l-control border border-r-0 border-border bg-navy-50 px-3 font-semibold text-navy-900">+244</span>
                        <input
                            id="phone"
                            v-model="phoneForm.phone"
                            class="input rounded-l-none"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel-national"
                            placeholder="923 456 789"
                            required
                            autofocus
                        />
                    </div>
                </FormField>
                <button type="submit" class="btn-primary w-full" :disabled="phoneForm.processing || phoneForm.phone.length < 9">
                    {{ phoneForm.processing ? 'A enviar…' : 'Receber código' }}
                </button>
            </form>
        </template>

        <template v-else>
            <h1 class="text-title">Introduza o código</h1>
            <p class="mt-1 text-caption text-ink-muted">Enviámos um código de {{ codeLength }} dígitos para {{ maskedPhone }}.</p>

            <form class="mt-6 grid gap-4" novalidate @submit.prevent="verify">
                <FormField label="Código" for="code" :error="codeForm.errors.code">
                    <input
                        id="code"
                        v-model="codeForm.code"
                        class="input text-center text-2xl font-bold tracking-[0.5em]"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="codeLength"
                        pattern="[0-9]*"
                        required
                        autofocus
                    />
                </FormField>
                <button type="submit" class="btn-primary w-full" :disabled="codeForm.processing || codeForm.code.length !== codeLength">
                    {{ codeForm.processing ? 'A verificar…' : 'Entrar' }}
                </button>
            </form>

            <div class="mt-4 flex items-center justify-between text-caption">
                <button type="button" class="font-semibold text-navy-700 underline" @click="router.post('/entrar/alterar-numero')">Alterar número</button>
                <button type="button" class="font-semibold text-orange-600 disabled:text-ink-subtle" :disabled="countdown > 0" @click="resend">
                    {{ countdown > 0 ? `Reenviar em ${countdown}s` : 'Reenviar código' }}
                </button>
            </div>
        </template>
    </section>

    <p class="mt-4 text-center text-caption text-ink-muted">Nunca partilhe este código. A LONGA nunca o pede por telefone.</p>
</template>
