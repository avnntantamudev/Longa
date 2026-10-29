<script setup lang="ts">
import ChatPanel from '@/components/ChatPanel.vue';
import MiniMap from '@/components/MiniMap.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { eventLabel, sessionPhase, stateBadge, type Addendum, type Quote, type Session, type SessionAction, type SessionEventItem } from '@/lib/session';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

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
const isProvider = computed(() => session.value?.role === 'provider');
const counterpart = computed(() => (session.value?.role === 'client' ? session.value.provider?.name : session.value?.client?.name) ?? '');
const counterpartPhoto = computed(() => (session.value?.role === 'client' ? session.value.provider?.photo_url : session.value?.client?.photo_url) ?? null);
const phase = computed(() => (session.value ? sessionPhase(session.value) : 'other'));
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
    if (s.data.pricing_mode === 'quote') {
        const [q, a] = await Promise.all([
            api.get<{ data: Quote[] }>(`/sessions/${props.sessionId}/quotes`),
            api.get<{ data: Addendum[] }>(`/sessions/${props.sessionId}/addenda`),
        ]);
        quotes.value = q.data;
        addenda.value = a.data;
    }
}

// Orçamentos: o mais recente primeiro; formulário do técnico e nota do cliente
const quotes = ref<Quote[]>([]);
const latestQuote = computed(() => quotes.value[0] ?? null);
const quoteForm = reactive({ labour_amount: null as number | null, materials_amount: null as number | null, execution_days: 1, notes: '' });
const quoteNote = ref('');

// Adendas (trabalho extra durante a execução)
const addenda = ref<Addendum[]>([]);
const addendumForm = reactive({ description: '', amount: null as number | null });

async function proposeAddendum(): Promise<void> {
    await call('addendum', `/sessions/${props.sessionId}/addenda`, { ...addendumForm }, 'Trabalho extra enviado ao cliente para aprovação.');
    addendumForm.description = '';
    addendumForm.amount = null;
}

async function answerAddendum(item: Addendum, action: 'approve' | 'reject' | 'pay'): Promise<void> {
    const messages = { approve: 'Trabalho extra aprovado.', reject: 'Trabalho extra recusado.', pay: 'Trabalho extra pago. O valor fica retido até confirmar o serviço.' };
    await call('addendum', `/addenda/${item.id}/${action}`, action === 'pay' ? { method: payMethod.value } : {}, messages[action]);
}

async function sendQuote(): Promise<void> {
    await call('send_quote', `/sessions/${props.sessionId}/quotes`, { ...quoteForm, materials_amount: quoteForm.materials_amount ?? 0 }, 'Orçamento enviado ao cliente.');
}

async function answerQuote(action: 'accept' | 'revision' | 'refuse'): Promise<void> {
    if (!latestQuote.value) return;
    const messages = { accept: 'Orçamento aceite. O preço fica bloqueado.', revision: 'Pedido de revisão enviado ao técnico.', refuse: 'Orçamento recusado.' };
    await call('answer_quote', `/quotes/${latestQuote.value.id}/${action}`, { note: quoteNote.value || null }, messages[action]);
}

/** Como run(), para endpoints fora de /sessions/{id}/... */
async function call(action: SessionAction, path: string, body: unknown, success: string): Promise<void> {
    error.value = '';
    notice.value = '';
    busy.value = action;
    if (!keys.has(action)) keys.set(action, newIdempotencyKey());
    try {
        await api.post(path, body, { idempotencyKey: keys.get(action) });
        keys.delete(action);
        notice.value = success;
        await load();
    } catch (e) {
        if (e instanceof ApiError) {
            keys.delete(action);
            const fields = e.fieldErrors();
            error.value = Object.values(fields)[0] ?? e.message;
        } else {
            error.value = 'Sem ligação. Tente de novo: a operação não será repetida.';
        }
    } finally {
        busy.value = null;
    }
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
    await run('start', 'start', undefined, 'Serviço iniciado. Bom trabalho!');
}

async function finishLesson() {
    busy.value = 'finish';
    try {
        await api.post(`/sessions/${props.sessionId}/finish`);
        router.visit(`/servicos/${props.sessionId}/qr`);
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
    <Head title="Serviço" />

    <template v-if="session">
        <!-- Cartão do outro participante -->
        <section class="rounded-sheet bg-navy-900 p-5 text-white">
            <div class="flex items-center gap-4">
                <UserAvatar :name="counterpart" :src="counterpartPhoto" :size="64" />
                <div class="min-w-0">
                    <p class="text-caption font-semibold text-orange-400">{{ session.role === 'client' ? 'Técnico' : 'Cliente' }}</p>
                    <p class="truncate text-title">{{ counterpart }}</p>
                    <span class="badge badge-brand mt-1">{{ session.category }}</span>
                    <span v-if="session.provider?.verified" class="badge badge-success mt-1 ml-1">Verificado BISCA</span>
                    <span v-if="session.provider?.vip" class="badge mt-1 ml-1 bg-orange-500 text-white">⭐ VIP</span>
                </div>
            </div>
            <p v-if="session.provider?.bio" class="mt-3 text-caption text-navy-100">{{ session.provider.bio }}</p>
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
                <div v-if="isProvider"><dt class="text-ink-muted">Recebe</dt><dd class="font-semibold">{{ kwanza(session.provider_net ?? 0) }}</dd></div>
                <div><dt class="text-ink-muted">Pagamento</dt><dd class="font-semibold">{{ session.payment_method === 'cash' ? 'Em mão' : 'Pelo BISCA' }}</dd></div>
            </dl>
            <div v-if="session.modality !== 'online' && ['scheduled', 'pending_payment', 'in_progress'].includes(phase)" class="mt-3">
                <MiniMap :lat="session.latitude" :lng="session.longitude" :approximate="false" :label="session.neighborhood" />
            </div>
        </section>

        <!-- Estado e próximos passos -->
        <section class="card mt-4 grid gap-3" aria-live="polite">
            <p v-if="notice" class="rounded-control bg-success/10 p-3 text-caption font-semibold text-success">{{ notice }}</p>
            <p v-if="error" class="rounded-control bg-danger/10 p-3 text-caption font-semibold text-danger" role="alert">{{ error }}</p>

            <!-- Pagamento (cliente) -->
            <template v-if="phase === 'pending_payment'">
                <template v-if="!isProvider">
                    <p class="font-semibold">Pague para garantir o serviço</p>
                    <p class="text-caption text-ink-muted">
                        O valor fica retido pelo BISCA e só é libertado ao técnico depois de confirmar o serviço.
                        <template v-if="session.payment_due_at">Prazo: {{ when(session.payment_due_at) }}.</template>
                    </p>
                    <div v-if="has('pay')" class="flex gap-2">
                        <button type="button" class="chip" :class="{ 'chip-active': payMethod === 'multicaixa_express' }" @click="payMethod = 'multicaixa_express'">Multicaixa Express</button>
                        <button type="button" class="chip" :class="{ 'chip-active': payMethod === 'reference' }" @click="payMethod = 'reference'">Referência</button>
                    </div>
                    <button v-if="has('pay')" class="btn-primary w-full" :disabled="busy !== null" @click="run('pay', 'pay', { method: payMethod }, 'Pagamento confirmado. O serviço está agendado.')">
                        {{ busy === 'pay' ? 'A processar…' : `Pagar ${kwanza(session.amount)}` }}
                    </button>
                    <p v-else class="text-caption text-danger">O prazo de pagamento terminou.</p>
                </template>
                <p v-else class="text-caption text-ink-muted">À espera do pagamento do cliente (até 10 minutos).</p>
            </template>

            <p v-else-if="phase === 'payment_failed'" class="text-caption text-ink-muted">O pagamento não foi concluído e o serviço não foi marcado.</p>

            <!-- Orçamento: diagnóstico e resposta -->
            <template v-else-if="phase === 'quote'">
                <template v-if="session.state === 'diagnosing'">
                    <p class="font-semibold">Diagnóstico no local</p>
                    <p v-if="session.visit_fee" class="text-caption text-ink-muted">
                        Taxa de visita: {{ kwanza(session.visit_fee) }}, paga em mão ao técnico (descontada no serviço se o orçamento for aceite).
                    </p>
                    <p v-if="latestQuote?.status === 'revision_requested'" class="text-caption text-ink-muted">
                        O cliente pediu revisão{{ latestQuote.client_note ? `: "${latestQuote.client_note}"` : '' }}.
                    </p>
                    <form v-if="has('send_quote')" class="grid gap-2" @submit.prevent="sendQuote">
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model.number="quoteForm.labour_amount" class="input" type="number" min="0" step="500" inputmode="numeric" placeholder="Mão de obra (Kz)" aria-label="Mão de obra (Kz)" />
                            <input v-model.number="quoteForm.materials_amount" class="input" type="number" min="0" step="500" inputmode="numeric" placeholder="Materiais (Kz)" aria-label="Materiais (Kz)" />
                        </div>
                        <label class="text-caption">Prazo de execução (dias)
                            <input v-model.number="quoteForm.execution_days" class="input mt-1" type="number" min="1" max="365" />
                        </label>
                        <textarea v-model="quoteForm.notes" class="input min-h-20 py-3" maxlength="1000" placeholder="O que vai fazer e que materiais usa" />
                        <button type="submit" class="btn-primary w-full" :disabled="busy !== null || !quoteForm.labour_amount">
                            {{ busy === 'send_quote' ? 'A enviar…' : 'Enviar orçamento' }}
                        </button>
                    </form>
                    <p v-else class="text-caption text-ink-muted">O técnico está a ver o problema e vai enviar o orçamento.</p>
                </template>
                <template v-else-if="latestQuote">
                    <p class="font-semibold">Orçamento{{ latestQuote.revision > 1 ? ` (revisão ${latestQuote.revision})` : '' }}</p>
                    <dl class="grid grid-cols-2 gap-1 text-caption">
                        <dt class="text-ink-muted">Mão de obra</dt><dd class="text-right">{{ kwanza(latestQuote.labour_amount) }}</dd>
                        <dt class="text-ink-muted">Materiais</dt><dd class="text-right">{{ kwanza(latestQuote.materials_amount) }}</dd>
                        <dt v-if="latestQuote.visit_fee_deducted" class="text-ink-muted">Taxa de visita já paga</dt>
                        <dd v-if="latestQuote.visit_fee_deducted" class="text-right">− {{ kwanza(latestQuote.visit_fee_deducted) }}</dd>
                        <dt class="font-semibold">A pagar</dt><dd class="text-right font-semibold">{{ kwanza(latestQuote.amount_due) }}</dd>
                        <dt class="text-ink-muted">Prazo</dt><dd class="text-right">{{ latestQuote.execution_days }} {{ latestQuote.execution_days === 1 ? 'dia' : 'dias' }}</dd>
                        <dt class="text-ink-muted">Válido até</dt><dd class="text-right">{{ when(latestQuote.valid_until) }}</dd>
                    </dl>
                    <p v-if="latestQuote.notes" class="text-caption text-ink-muted">{{ latestQuote.notes }}</p>
                    <template v-if="has('answer_quote')">
                        <input v-model="quoteNote" class="input" maxlength="500" placeholder="Comentário (opcional)" />
                        <button class="btn-primary w-full" :disabled="busy !== null" @click="answerQuote('accept')">Aceitar orçamento</button>
                        <div class="flex gap-2">
                            <button class="btn-outline flex-1" :disabled="busy !== null" @click="answerQuote('revision')">Pedir revisão</button>
                            <button class="btn-outline flex-1 text-danger" :disabled="busy !== null" @click="answerQuote('refuse')">Recusar</button>
                        </div>
                        <p v-if="session.visit_fee" class="text-caption text-ink-muted">Se recusar, o técnico fica com a taxa de visita.</p>
                    </template>
                    <p v-else class="text-caption text-ink-muted">À espera da resposta do cliente.</p>
                </template>
            </template>

            <!-- Serviço agendado -->
            <template v-else-if="phase === 'scheduled'">
                <p class="text-caption text-ink-muted">
                    {{ isProvider ? 'Inicie o serviço quando chegar (a partir de 15 minutos antes).' : 'O técnico inicia o serviço quando chegar.' }}
                </p>
                <button v-if="has('start')" class="btn-primary w-full" :disabled="busy !== null" @click="startLesson">
                    {{ busy === 'start' ? 'A iniciar…' : 'Iniciar serviço' }}
                </button>
                <button v-if="has('diagnosis')" class="btn-primary w-full" :disabled="busy !== null" @click="run('diagnosis', 'diagnosis', undefined, 'Diagnóstico iniciado.')">
                    {{ busy === 'diagnosis' ? 'A começar…' : 'Cheguei: começar diagnóstico' }}
                </button>
            </template>

            <!-- Em curso -->
            <template v-else-if="session.state === 'in_progress'">
                <p class="font-semibold">Serviço a decorrer</p>
                <p class="text-caption text-ink-muted">
                    {{ isProvider ? 'No fim, toque em "Terminar serviço" e mostre o QR ao cliente.' : 'No fim, o técnico mostra-lhe um QR para confirmar o serviço.' }}
                </p>
                <button v-if="has('finish')" class="btn-primary w-full" :disabled="busy !== null" @click="finishLesson">
                    {{ busy === 'finish' ? 'A terminar…' : 'Terminar serviço' }}
                </button>

                <!-- Trabalho extra (adendas ao orçamento) -->
                <div v-if="addenda.length || has('addendum')" class="grid gap-2 rounded-control bg-navy-50 p-3">
                    <p class="text-caption font-semibold">Trabalho extra</p>
                    <div v-for="item in addenda" :key="item.id" class="grid gap-1 border-b border-border pb-2 last:border-0">
                        <p class="text-caption">{{ item.description }} · <strong>{{ kwanza(item.amount) }}</strong></p>
                        <p class="text-caption text-ink-muted">{{ item.status_label }}{{ item.team_review ? ' (acima do limite: revisto pela equipa do BISCA)' : '' }}</p>
                        <div v-if="!isProvider && item.status === 'pending_client'" class="flex gap-2">
                            <button class="btn-primary flex-1" :disabled="busy !== null" @click="answerAddendum(item, 'approve')">Aprovar</button>
                            <button class="btn-outline flex-1" :disabled="busy !== null" @click="answerAddendum(item, 'reject')">Recusar</button>
                        </div>
                        <button v-if="!isProvider && item.status === 'approved' && session.payment_method === 'electronic'" class="btn-primary w-full" :disabled="busy !== null" @click="answerAddendum(item, 'pay')">
                            Pagar {{ kwanza(item.amount) }}
                        </button>
                    </div>
                    <form v-if="has('addendum') && !addenda.some((a) => ['pending_client', 'pending_team'].includes(a.status))" class="grid gap-2" @submit.prevent="proposeAddendum">
                        <textarea v-model="addendumForm.description" class="input min-h-16 py-2" maxlength="1000" placeholder="O que é preciso fazer a mais" />
                        <input v-model.number="addendumForm.amount" class="input" type="number" min="1" step="500" inputmode="numeric" placeholder="Valor (Kz)" />
                        <button type="submit" class="btn-outline w-full" :disabled="busy !== null || !addendumForm.amount || addendumForm.description.length < 5">Propor trabalho extra</button>
                        <p class="text-caption text-ink-muted">Só faça o trabalho extra depois de o cliente aprovar.</p>
                    </form>
                </div>
            </template>

            <!-- À espera de confirmação -->
            <template v-else-if="phase === 'awaiting_confirmation'">
                <template v-if="isProvider">
                    <p class="font-semibold">À espera da confirmação do cliente</p>
                    <p class="text-caption text-ink-muted">{{ kwanza(session.provider_net ?? 0) }} pendentes, libertados quando o cliente ler o QR.</p>
                    <Link v-if="has('show_qr')" :href="`/servicos/${session.id}/qr`" class="btn-primary w-full">Mostrar QR</Link>
                </template>
                <template v-else>
                    <p class="font-semibold">O serviço aconteceu?</p>
                    <p class="text-caption text-ink-muted">
                        Leia o QR do técnico para confirmar.
                        <template v-if="session.confirmation_deadline">Prazo: {{ when(session.confirmation_deadline) }}.</template>
                    </p>
                    <Link :href="`/servicos/${session.id}/confirmar`" class="btn-primary w-full">Escanear QR para confirmar</Link>
                    <button v-if="confirming !== 'confirm_without_qr'" class="btn-outline w-full" @click="confirming = 'confirm_without_qr'">Confirmar sem QR</button>
                    <div v-else class="grid gap-2 rounded-control bg-navy-50 p-3">
                        <p class="text-caption">Confirma que o serviço aconteceu? O valor é libertado ao técnico e não pode ser revertido.</p>
                        <div class="flex gap-2">
                            <button class="btn-primary flex-1" :disabled="busy !== null" @click="run('confirm_without_qr', 'confirm-without-qr', undefined, 'Serviço confirmado. Obrigado!')">Sim, confirmar</button>
                            <button class="btn-outline flex-1" @click="confirming = null">Voltar</button>
                        </div>
                    </div>
                    <Link :href="`/servicos/${session.id}/disputa`" class="text-center text-caption font-semibold text-danger">O serviço não aconteceu ou houve um problema</Link>
                </template>
            </template>

            <!-- Concluída -->
            <template v-else-if="phase === 'completed'">
                <p class="font-semibold text-success">Serviço concluído</p>
                <p v-if="isProvider && session.payment_method === 'electronic'" class="text-caption text-ink-muted">{{ kwanza(session.provider_net ?? 0) }} na sua carteira.</p>
                <template v-if="has('confirm_cash')">
                    <p class="text-caption text-ink-muted">Confirme que pagou {{ kwanza(session.amount) }} ao técnico em mão.</p>
                    <button class="btn-primary w-full" :disabled="busy !== null" @click="run('confirm_cash', 'confirm-cash', undefined, 'Pagamento em mão confirmado.')">Confirmei que paguei</button>
                </template>
            </template>

            <!-- Faltas -->
            <template v-else-if="['no_show_provider', 'no_show_client'].includes(phase)">
                <p class="font-semibold">{{ phase === 'no_show_provider' ? 'Falta do técnico reportada' : 'Falta do cliente reportada' }}</p>
                <p v-if="session.no_show_contest_until" class="text-caption text-ink-muted">Pode ser contestada até {{ when(session.no_show_contest_until) }}.</p>
                <Link v-if="has('dispute')" :href="`/servicos/${session.id}/disputa`" class="btn-primary w-full">Contestar a falta</Link>
            </template>

            <p v-else-if="session.state === 'disputed'" class="text-caption text-ink-muted">Disputa em análise pelo BISCA. O valor fica retido até à decisão.</p>
            <p v-else class="text-caption text-ink-muted">{{ session.state_label }}.</p>

            <!-- Cancelar / reportar falta -->
            <template v-if="has('cancel')">
                <button v-if="confirming !== 'cancel'" class="btn-outline w-full" @click="confirming = 'cancel'">Cancelar serviço</button>
                <div v-else class="grid gap-2 rounded-control bg-navy-50 p-3">
                    <p class="text-caption">
                        <template v-if="isProvider">O cliente é reembolsado na totalidade. Cancelamentos em cima da hora afetam a sua pontuação.</template>
                        <template v-else-if="late">Faltam menos de 6 horas: o técnico fica com 25% do valor e recebe o reembolso do resto.</template>
                        <template v-else>Recebe o reembolso total.</template>
                    </p>
                    <input v-model="cancelReason" class="input" maxlength="255" placeholder="Motivo (opcional)" />
                    <div class="flex gap-2">
                        <button class="btn-primary flex-1" :disabled="busy !== null" @click="run('cancel', 'cancel', { reason: cancelReason || null }, 'Serviço cancelado.')">Cancelar serviço</button>
                        <button class="btn-outline flex-1" @click="confirming = null">Voltar</button>
                    </div>
                </div>
            </template>
            <template v-if="has('no_show')">
                <button v-if="confirming !== 'no_show'" class="text-caption font-semibold text-danger" @click="confirming = 'no_show'">
                    {{ isProvider ? 'O cliente não apareceu' : 'O técnico não apareceu' }}
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

        <h2 class="mt-6 text-title">Chat do serviço</h2>
        <p class="mb-3 text-caption text-ink-muted">Combine os detalhes aqui. Os contactos pessoais ficam escondidos nesta fase.</p>
        <ChatPanel :session-id="session.id" :me-id="meId" />
    </template>

    <p v-else class="text-ink-muted">A carregar…</p>
</template>
