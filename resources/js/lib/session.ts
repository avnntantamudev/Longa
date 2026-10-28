/**
 * Tipos e textos da execução do serviço (espelham SessionResource e AvailableActions no servidor).
 */
export type SessionAction =
    | 'pay'
    | 'start'
    | 'cancel'
    | 'no_show'
    | 'finish'
    | 'show_qr'
    | 'confirm'
    | 'confirm_without_qr'
    | 'dispute'
    | 'confirm_cash';

export interface Session {
    id: number;
    state: string;
    state_label: string;
    actions: SessionAction[];
    title: string;
    category: string;
    scheduled_at: string;
    ends_at: string | null;
    duration_minutes: number;
    payment_method: 'electronic' | 'cash';
    payment_due_at: string | null;
    started_at: string | null;
    ended_at: string | null;
    confirmed_at: string | null;
    confirmation_deadline: string | null;
    no_show_contest_until: string | null;
    cash_confirmed_at: string | null;
    modality: string;
    municipality: string;
    neighborhood: string;
    latitude: number;
    longitude: number;
    amount: number;
    commission?: number;
    provider_net?: number;
    role: 'client' | 'provider';
    client?: { name: string | null; photo_url: string | null; photo_thumb_url: string | null };
    provider?: { name: string | null; photo_url: string | null; photo_thumb_url: string | null; bio: string | null; years_experience: number | null; neighborhood: string | null; verified: boolean; vip: boolean; score: number };
}

export interface QrToken {
    token: string;
    code: string;
    valid_from: string;
    expires_at: string;
}

export interface QrBatch {
    batch_id: string;
    rotation_seconds: number;
    tokens: QrToken[];
    server_time: string;
}

export interface SessionEventItem {
    type: string;
    from_state: string | null;
    to_state: string | null;
    by: 'client' | 'provider' | 'admin' | 'system';
    created_at: string;
}

/** Badge por estado (classes do kit) */
export function stateBadge(state: string): string {
    if (['completed'].includes(state)) return 'badge-success';
    if (['disputed', 'no_show_provider', 'no_show_client', 'payment_failed'].includes(state)) return 'badge-warning';
    if (['pending_payment', 'awaiting_confirmation', 'in_progress'].includes(state)) return 'badge-brand';
    return 'badge-neutral';
}

/** Texto da linha do tempo */
export function eventLabel(type: string): string {
    const labels: Record<string, string> = {
        payment_requested: 'Pagamento pedido',
        payment_confirmed: 'Pagamento recebido (retido pelo BISCA)',
        payment_failed: 'Pagamento falhou',
        scheduled: 'Serviço agendado',
        started: 'Serviço iniciado',
        finished: 'Serviço terminado',
        qr_issued: 'QR de confirmação gerado',
        qr_scanned: 'QR lido pelo cliente',
        confirmed: 'Serviço confirmado',
        confirmed_without_qr: 'Serviço confirmado sem QR',
        cancelled: 'Serviço cancelado',
        no_show_reported: 'Falta reportada',
        no_show_settled: 'Falta liquidada',
        dispute_opened: 'Disputa aberta',
        dispute_resolved: 'Disputa decidida',
        reminder_sent: 'Lembrete enviado',
        auto_awaiting: 'À espera de confirmação',
        auto_completed: 'Confirmada automaticamente',
        auto_refunded: 'Reembolsada automaticamente',
        cash_confirmed: 'Pagamento em mão confirmado',
        risk_flagged: 'Sinal de risco registado',
    };
    return labels[type] ?? type.replaceAll('_', ' ');
}

/** O QR leva o serviço e o token, para o leitor recusar QR de outro serviço antes de enviar */
export function qrPayload(sessionId: number, token: string): string {
    return `LONGA:${sessionId}:${token}`;
}

export function parseQrPayload(raw: string): { sessionId: number; token: string } | null {
    const match = raw.trim().match(/^LONGA:(\d+):([A-Za-z0-9_-]{16,64})$/);
    return match ? { sessionId: Number(match[1]), token: match[2]! } : null;
}
