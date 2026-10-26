import { useNetworkState } from '@/composables/useNetworkState';
import { requestPin } from '@/lib/pinPrompt';

/**
 * Cliente da API /api/v1 para a PWA.
 *  - Autenticação por cookie de sessão (Sanctum SPA): envia o cabeçalho X-XSRF-TOKEN.
 *  - Erros no formato único da API (ver docs/api-errors.md) chegam como ApiError.
 *  - Falhas de rede passam pelo estado global "a enviar / falhou / tentar de novo".
 */
export interface ApiErrorBody {
    code: string;
    message: string;
    details?: Record<string, unknown>;
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        message: string,
        public readonly details: Record<string, unknown> = {},
    ) {
        super(message);
    }

    /** Erros de validação por campo: { campo: "primeira mensagem" } */
    fieldErrors(): Record<string, string> {
        if (this.code !== 'validation_failed') {
            return {};
        }
        const result: Record<string, string> = {};
        for (const [field, messages] of Object.entries(this.details)) {
            result[field] = Array.isArray(messages) ? String(messages[0]) : String(messages);
        }
        return result;
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
    /** Operações com dinheiro: a mesma chave em todas as tentativas da mesma ação (ver newIdempotencyKey) */
    idempotencyKey?: string;
}

/** Uma chave por ação do utilizador; reutilizar em repetições depois de falha de rede. */
export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}

async function request<T>(method: Method, path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (options.idempotencyKey) {
        headers['Idempotency-Key'] = options.idempotencyKey;
    }
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let payload: BodyInit | undefined;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const response = await fetch(`/api/v1${path}`, { method, headers, body: payload, credentials: 'same-origin' });

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => null);

    // Sessão terminada (logout noutro dispositivo, expiração): volta ao login
    if (response.status === 401 && method !== 'GET') {
        window.location.href = '/entrar';
    }

    if (!response.ok) {
        const error = (data?.error ?? {}) as Partial<ApiErrorBody>;
        throw new ApiError(
            response.status,
            error.code ?? 'http_error',
            error.message ?? 'Não foi possível concluir o pedido.',
            error.details ?? {},
        );
    }

    return data as T;
}

/**
 * Executa o pedido com o estado global de rede. Erros da API (4xx) não contam como falha de rede:
 * são mostrados no próprio formulário.
 */
function send<T>(method: Method, path: string, body?: unknown, options?: RequestOptions, pinRetried = false): Promise<T> {
    const network = useNetworkState();
    return network.track(() => request<T>(method, path, body, options)).catch(async (error: unknown) => {
        if (error instanceof ApiError && error.status < 500) {
            network.dismiss();
        }
        if (error instanceof ApiError && error.status === 423) {
            // Pagamentos: pedir o PIN e repetir o mesmo pedido (mesma chave de idempotência)
            if (error.code === 'pin_required' && !pinRetried) {
                await requestPin();
                return send<T>(method, path, body, options, true);
            }
            // Sessão bloqueada por inatividade ou sem PIN criado: ir para o ecrã certo
            const redirect = error.details.redirect;
            if (typeof redirect === 'string') {
                window.location.href = redirect;
            }
        }
        throw error;
    });
}

/** Pedido sem as regras de PIN e rede (usado pelo próprio teclado de PIN). */
export function rawRequest<T>(method: Method, path: string, body?: unknown): Promise<T> {
    return request<T>(method, path, body);
}

export const api = {
    get: <T>(path: string) => send<T>('GET', path),
    post: <T>(path: string, body?: unknown, options?: RequestOptions) => send<T>('POST', path, body, options),
    put: <T>(path: string, body?: unknown) => send<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => send<T>('PATCH', path, body),
    delete: <T>(path: string) => send<T>('DELETE', path),
};
