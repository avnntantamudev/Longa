<script setup lang="ts">
import FormField from '@/components/FormField.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

type Channel = 'sms' | 'whatsapp';

const props = defineProps<{ phone: string | null; channel: Channel; codeLength: number; resendIn: number; resetPin?: boolean }>();

const channels: Array<{ value: Channel; label: string }> = [
    { value: 'sms', label: 'SMS' },
    { value: 'whatsapp', label: 'WhatsApp' },
];
const channelLabel = (c: Channel) => (c === 'whatsapp' ? 'WhatsApp' : 'SMS');

const phoneForm = useForm({ phone: '', channel: 'sms' as Channel });
const codeForm = useForm({ phone: props.phone ?? '', code: '' });
const resendError = ref<string | null>(null);

const step = computed(() => (props.phone ? 'code' : 'phone'));

// Contagem por canal: depois de um SMS pode pedir logo por WhatsApp (e vice-versa)
const countdown = reactive<Record<Channel, number>>({ sms: 0, whatsapp: 0 });
let timer: ReturnType<typeof setInterval> | undefined;

function startCountdown(channel: Channel): void {
    countdown[channel] = props.resendIn;
    clearInterval(timer);
    timer = setInterval(() => {
        countdown.sms = Math.max(0, countdown.sms - 1);
        countdown.whatsapp = Math.max(0, countdown.whatsapp - 1);
        if (countdown.sms === 0 && countdown.whatsapp === 0) {
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
            startCountdown(props.channel);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(timer));

function sendCode(): void {
    phoneForm.post('/entrar/codigo', { preserveScroll: true });
}

function resend(channel: Channel): void {
    resendError.value = null;
    router.post(
        '/entrar/codigo',
        { phone: props.phone, channel },
        {
            preserveScroll: true,
            onSuccess: () => startCountdown(channel),
            onError: (errors) => (resendError.value = errors.phone ?? null),
        },
    );
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
            <h1 class="text-title">{{ resetPin ? 'Esqueceu o PIN?' : 'Entrar ou criar conta' }}</h1>
            <p class="mt-1 text-caption text-ink-muted">
                {{ resetPin ? 'Confirme o seu número com um código e crie um PIN novo.' : 'Enviamos um código de acesso uma vez; depois entra só com o seu PIN.' }}
            </p>

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

                <fieldset class="grid gap-2">
                    <legend class="mb-1.5 text-caption font-semibold">Receber o código por</legend>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup">
                        <button
                            v-for="option in channels"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="phoneForm.channel === option.value"
                            class="chip min-h-12 justify-center"
                            :class="{ 'chip-active': phoneForm.channel === option.value }"
                            @click="phoneForm.channel = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </fieldset>

                <button type="submit" class="btn-primary w-full" :disabled="phoneForm.processing || phoneForm.phone.length < 9">
                    {{ phoneForm.processing ? 'A enviar…' : `Receber código por ${channelLabel(phoneForm.channel)}` }}
                </button>
            </form>
        </template>

        <template v-else>
            <h1 class="text-title">Introduza o código</h1>
            <p class="mt-1 text-caption text-ink-muted">
                Enviámos um código de {{ codeLength }} dígitos por {{ channelLabel(channel) }} para {{ maskedPhone }}.
            </p>

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

            <div class="mt-5 grid gap-2">
                <p class="text-caption font-semibold text-ink-muted">Não recebeu? Escolha como quer receber:</p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn-secondary min-h-11 text-caption" :disabled="countdown.sms > 0" @click="resend('sms')">
                        {{ countdown.sms > 0 ? `SMS · ${countdown.sms}s` : 'Por SMS' }}
                    </button>
                    <button type="button" class="btn-outline min-h-11 text-caption" :disabled="countdown.whatsapp > 0" @click="resend('whatsapp')">
                        {{ countdown.whatsapp > 0 ? `WhatsApp · ${countdown.whatsapp}s` : 'Por WhatsApp' }}
                    </button>
                </div>
                <p v-if="resendError" class="text-caption text-danger" role="alert">{{ resendError }}</p>
                <button type="button" class="mt-1 justify-self-start text-caption font-semibold text-navy-700 underline" @click="router.post('/entrar/alterar-numero')">
                    Alterar número
                </button>
            </div>
        </template>
    </section>

    <p class="mt-4 text-center text-caption text-ink-muted">Nunca partilhe este código. A LONGA nunca o pede por telefone.</p>
</template>
