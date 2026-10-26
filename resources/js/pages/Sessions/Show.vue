<script setup lang="ts">
import ChatPanel from '@/components/ChatPanel.vue';
import MiniMap from '@/components/MiniMap.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { eventLabel, stateBadge, type Session, type SessionAction, type SessionEventItem } from '@/lib/session';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ sessionId: number }>();

const page = usePage();
const session = ref<Session | null>(null);
const events = ref<SessionEventItem[]>([]);
const busy = ref<SessionAction | null>(null);
const error = ref('');
const notice = ref('');
const payMethod = ref<'multicaixa_express' | 'reference'>('multicaixa_express');
const cancelReason = ref('');
const confirming = ref<SessionAction | null>(null);
// Uma chave por ação: repetir depois de falha de rede não duplica a operação
const keys = new Map<SessionAction, string>();

const meId = computed(() => page.props.auth.user?.id ?? 0);
const isTrainer = computed(() => session.value?.role === 'trainer');
const counterpart = computed(() => (session.value?.role === 'student' ? session.value.trainer?.name : session.value?.student?.name) ?? '');
const counterpartPhoto = computed(() => (session.value?.role === 'student' ? session.value.trainer?.photo_url : session.value?.student?.photo_url) ?? null);
const has = (action: SessionAction) => session.value?.actions.includes(action) ?? false;
const late = computed(() => {
    if (!session.value) return false;
    return new Date(session.value.scheduled_at).getTime() - Date.now() < 6 * 3600 * 1000;
});

async function load() {
    const [s, e] = await Promise.all([
        api.get<{ data: Session }>(`/sessions/${props.sessionId}`),
        api.get<{ data: SessionEventItem[] }>(`/sessions/${props.sessionId}/events`),
    ]);
    session.value = s.data;
    events.value = e.data.slice().reverse();
}

async function run(action: SessionAction, path: string, body?: unknown, success?: string) {
    error.value = '';
    notice.value = '';
    busy.value = action;
    if (!keys.has(action)) keys.set(action, newIdempotencyKey());
    try {
        await api.post(`/sessions/${props.sessionId}/${path}`, body, { idempotencyKey: keys.get(action) });
        keys.delete(action);
        confirming.value = null;
        if (success) notice.value = success;
        await load();
    } catch (e) {
        if (e instanceof ApiError) {
            keys.delete(action); // resposta definitiva do servidor: a próxima tentativa é uma ação nova
            error.value = e.message;
            await load().catch(() => undefined);
        } else {
            error.value = 'Sem ligação. Tente de novo: a operação não será repetida.';
        }
    } finally {
        busy.value = null;
    }
}

async function startLesson() {
    await run('start', 'start', undefined, 'Aula iniciada. Bom trabalho!');
}

async function finishLesson() {
    busy.value = 'finish';
    try {
        await api.post(`/sessions/${props.sessionId}/finish`);
        router.visit(`/aulas/${props.sessionId}/qr`);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Sem ligação. Tente de novo.';
    } finally {
        busy.value = null;
    }
}

usePrivateChannel(`session.${props.sessionId}`, {
    'session.started': () => load(),
    'session.qr_issued': () => load(),
    'session.confirmed': () => load(),
    'session.cancelled': () => load(),
    'session.dispute_opened': () => load(),
    'session.dispute_resolved': () => load(),
});
// Rede de segurança se o tempo real não chegar
usePolling(() => load(), 20000);

onMounted(load);
</script>

<template>
    <Head title="Aula" />

    <template v-if="session">
        <!-- Cartão do outro participante -->
        <section class="rounded-sheet bg-navy-900 p-5 text-white">
            <div class="flex items-center gap-4">
                <UserAvatar :name="counterpart" :src="counterpartPhoto" :size="64" />
                <div class="min-w-0">
                    <p class="text-caption font-semibold text-orange-400">{{ session.role === 'student' ? 'Formador' : 'Aluno' }}</p>
                    <p class="truncate text-title">{{ counterpart }}</p>
                    <span class="badge badge-brand mt-1">{{ session.skill }}</span>
                    <span v-if="session.trainer?.verified" class="badge badge-success mt-1 ml-1">Verificado LONGA</span>
                </div>
            </div>
            <p v-if="session.trainer?.bio" class="mt-3 text-caption text-navy-100">{{ session.trainer.bio }}</p>
        </section>

        <section class="card mt-4">
            <div class="flex items-start justify-between gap-3">
                <p class="font-semibold">{{ session.title }}</p>
                <span class="badge shrink-0" :class="stateBadge(session.state)" data-testid="state">{{ session.state_label }}</span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-caption">
                <div><dt class="text-ink-muted">Quando</dt><dd class="font-semibold">{{ when(session.scheduled_at) }} · {{ session.duration_minutes }} min</dd></div>
                <div><dt class="text-ink-muted">Onde</dt><dd class="font-semibold">{{ session.modality === 'online' ? 'Online' : `${session.neighborhood}, ${session.municipality}` }}</dd></div>
                <div><dt class="text-ink-muted">Valor</dt><dd class="font-semibold">{{ kwanza(session.amount) }}</dd></div>
                <div v-if="isTrainer"><dt class="text-ink-muted">Recebe</dt><dd class="font-semibold">{{ kwanza(session.trainer_net ?? 0) }}</dd></div>
                <div><dt class="text-ink-muted">Pagamento</dt><dd class="font-semibold">{{ session.payment_method === 'cash' ? 'Em mão' : 'Pela LONGA' }}</dd></div>
            </dl>
            <div v-if="session.modality !== 'online' && ['scheduled', 'pending_payment', 'in_progress'].includes(session.state)" class="mt-3">
                <MiniMap :lat="session.latitude" :lng="session.longitude" :approximate="false" :label="session.neighborhood" />
            </div>
        </section>

        <!-- Estado e próximos passos -->
        <section class="card mt-4 grid gap-3" aria-live="polite">
            <p v-if="notice" class="rounded-control bg-success/10 p-3 text-caption font-semibold text-success">{{ notice }}</p>
            <p v-if="error" class="rounded-control bg-danger/10 p-3 text-caption font-semibold text-danger" role="alert">{{ error }}</p>

            <!-- Pagamento (aluno) -->
            <template v-if="session.state === 'pending_payment'">
                <template v-if="!isTrainer">
                    <p class="font-semibold">Pague para garantir a aula</p>
                    <p class="text-caption text-ink-muted">
                        O valor fica retido pela LONGA e só é libertado ao formador depois de confirmar a aula.
                        <template v-if="session.payment_due_at">Prazo: {{ when(session.payment_due_at) }}.</template>
                    </p>
                    <div v-if="has('pay')" class="flex gap-2">
                        <button type="button" class="chip" :class="{ 'chip-active': payMethod === 'multicaixa_express' }" @click="payMethod = 'multicaixa_express'">Multicaixa Express</button>
                        <button type="button" class="chip" :class="{ 'chip-active': payMethod === 'reference' }" @click="payMethod = 'reference'">Referência</button>
                    </div>
                    <button v-if="has('pay')" class="btn-primary w-full" :disabled="busy !== null" @click="run('pay', 'pay', { method: payMethod }, 'Pagamento confirmado. A aula está agendada.')">
                        {{ busy === 'pay' ? 'A processar…' : `Pagar ${kwanza(session.amount)}` }}
                    </button>
                    <p v-else class="text-caption text-danger">O prazo de pagamento terminou.</p>
                </template>
                <p v-else class="text-caption text-ink-muted">À espera do pagamento do aluno (até 10 minutos).</p>
            </template>

            <p v-else-if="session.state === 'payment_failed'" class="text-caption text-ink-muted">O pagamento não foi concluído e a aula não foi marcada.</p>

            <!-- Aula agendada -->
            <template v-else-if="session.state === 'scheduled'">
                <p class="text-caption text-ink-muted">
                    {{ isTrainer ? 'Inicie a aula quando chegar (a partir de 15 minutos antes).' : 'O formador inicia a aula quando chegar.' }}
                </p>
                <button v-if="has('start')" class="btn-primary w-full" :disabled="busy !== null" @click="startLesson">
                    {{ busy === 'start' ? 'A iniciar…' : 'Iniciar aula' }}
                </button>
            </template>

            <!-- Em curso -->
            <template v-else-if="session.state === 'in_progress'">
                <p class="font-semibold">Aula a decorrer</p>
                <p class="text-caption text-ink-muted">
                    {{ isTrainer ? 'No fim, toque em "Terminar aula" e mostre o QR ao aluno.' : 'No fim, o formador mostra-lhe um QR para confirmar a aula.' }}
                </p>
                <button v-if="has('finish')" class="btn-primary w-full" :disabled="busy !== null" @click="finishLesson">
                    {{ busy === 'finish' ? 'A terminar…' : 'Terminar aula' }}
                </button>
            </template>

            <!-- À espera de confirmação -->
            <template v-else-if="session.state === 'awaiting_confirmation'">
                <template v-if="isTrainer">
                    <p class="font-semibold">À espera da confirmação do aluno</p>
                    <p class="text-caption text-ink-muted">{{ kwanza(session.trainer_net ?? 0) }} pendentes, libertados quando o aluno ler o QR.</p>
                    <Link v-if="has('show_qr')" :href="`/aulas/${session.id}/qr`" class="btn-primary w-full">Mostrar QR</Link>
                </template>
                <template v-else>
                    <p class="font-semibold">A aula aconteceu?</p>
                    <p class="text-caption text-ink-muted">
                        Leia o QR do formador para confirmar.
                        <template v-if="session.confirmation_deadline">Prazo: {{ when(session.confirmation_deadline) }}.</template>
                    </p>
                    <Link :href="`/aulas/${session.id}/confirmar`" class="btn-primary w-full">Escanear QR para confirmar</Link>
                    <button v-if="confirming !== 'confirm_without_qr'" class="btn-outline w-full" @click="confirming = 'confirm_without_qr'">Confirmar sem QR</button>
                    <div v-else class="grid gap-2 rounded-control bg-navy-50 p-3">
                        <p class="text-caption">Confirma que a aula aconteceu? O valor é libertado ao formador e não pode ser revertido.</p>
                        <div class="flex gap-2">
                            <button class="btn-primary flex-1" :disabled="busy !== null" @click="run('confirm_without_qr', 'confirm-without-qr', undefined, 'Aula confirmada. Obrigado!')">Sim, confirmar</button>
                            <button class="btn-outline flex-1" @click="confirming = null">Voltar</button>
                        </div>
                    </div>
                    <Link :href="`/aulas/${session.id}/disputa`" class="text-center text-caption font-semibold text-danger">A aula não aconteceu ou houve um problema</Link>
                </template>
            </template>

            <!-- Concluída -->
            <template v-else-if="session.state === 'completed'">
                <p class="font-semibold text-success">Aula concluída</p>
                <p v-if="isTrainer && session.payment_method === 'electronic'" class="text-caption text-ink-muted">{{ kwanza(session.trainer_net ?? 0) }} na sua carteira.</p>
                <template v-if="has('confirm_cash')">
                    <p class="text-caption text-ink-muted">Confirme que pagou {{ kwanza(session.amount) }} ao formador em mão.</p>
                    <button class="btn-primary w-full" :disabled="busy !== null" @click="run('confirm_cash', 'confirm-cash', undefined, 'Pagamento em mão confirmado.')">Confirmei que paguei</button>
                </template>
            </template>

            <!-- Faltas -->
            <template v-else-if="['no_show_trainer', 'no_show_student'].includes(session.state)">
                <p class="font-semibold">{{ session.state === 'no_show_trainer' ? 'Falta do formador reportada' : 'Falta do aluno reportada' }}</p>
                <p v-if="session.no_show_contest_until" class="text-caption text-ink-muted">Pode ser contestada até {{ when(session.no_show_contest_until) }}.</p>
                <Link v-if="has('dispute')" :href="`/aulas/${session.id}/disputa`" class="btn-primary w-full">Contestar a falta</Link>
            </template>

            <p v-else-if="session.state === 'disputed'" class="text-caption text-ink-muted">Disputa em análise pela LONGA. O valor fica retido até à decisão.</p>
            <p v-else class="text-caption text-ink-muted">{{ session.state_label }}.</p>

            <!-- Cancelar / reportar falta -->
            <template v-if="has('cancel')">
                <button v-if="confirming !== 'cancel'" class="btn-outline w-full" @click="confirming = 'cancel'">Cancelar aula</button>
                <div v-else class="grid gap-2 rounded-control bg-navy-50 p-3">
                    <p class="text-caption">
                        <template v-if="isTrainer">O aluno é reembolsado na totalidade. Cancelamentos em cima da hora afetam a sua pontuação.</template>
                        <template v-else-if="late">Faltam menos de 6 horas: o formador fica com 25% do valor e recebe o reembolso do resto.</template>
                        <template v-else>Recebe o reembolso total.</template>
                    </p>
                    <input v-model="cancelReason" class="input" maxlength="255" placeholder="Motivo (opcional)" />
                    <div class="flex gap-2">
                        <button class="btn-primary flex-1" :disabled="busy !== null" @click="run('cancel', 'cancel', { reason: cancelReason || null }, 'Aula cancelada.')">Cancelar aula</button>
                        <button class="btn-outline flex-1" @click="confirming = null">Voltar</button>
                    </div>
                </div>
            </template>
            <template v-if="has('no_show')">
                <button v-if="confirming !== 'no_show'" class="text-caption font-semibold text-danger" @click="confirming = 'no_show'">
                    {{ isTrainer ? 'O aluno não apareceu' : 'O formador não apareceu' }}
                </button>
                <div v-else class="grid gap-2 rounded-control bg-navy-50 p-3">
                    <p class="text-caption">A outra parte tem 24 horas para contestar. Sem contestação, aplica-se a política de faltas.</p>
                    <div class="flex gap-2">
                        <button class="btn-primary flex-1" :disabled="busy !== null" @click="run('no_show', 'no-show', {}, 'Falta reportada.')">Reportar falta</button>
                        <button class="btn-outline flex-1" @click="confirming = null">Voltar</button>
                    </div>
                </div>
            </template>
        </section>

        <!-- Histórico -->
        <section v-if="events.length" class="card mt-4">
            <h2 class="font-semibold">Histórico</h2>
            <ol class="mt-3 grid gap-2 border-l-2 border-border pl-4 text-caption">
                <li v-for="(event, i) in events" :key="i">
                    <p class="font-semibold">{{ eventLabel(event.type) }}</p>
                    <p class="text-ink-muted">{{ when(event.created_at) }}</p>
                </li>
            </ol>
        </section>

        <h2 class="mt-6 text-title">Chat da aula</h2>
        <p class="mb-3 text-caption text-ink-muted">Combine os detalhes aqui. Os contactos pessoais ficam escondidos nesta fase.</p>
        <ChatPanel :session-id="session.id" :me-id="meId" />
    </template>

    <p v-else class="text-ink-muted">A carregar…</p>
</template>
