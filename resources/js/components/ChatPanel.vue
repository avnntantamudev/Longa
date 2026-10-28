<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import { usePolling, usePrivateChannel } from '@/composables/useRealtime';
import { api, ApiError } from '@/lib/api';
import { nextTick, onMounted, ref } from 'vue';

/**
 * Chat do serviço (texto + PDF) pensado para rede lenta:
 *  - envio otimista com client_id: a mensagem aparece logo como "a enviar";
 *  - se falhar fica "não enviada · tentar de novo" (o servidor não duplica reenvios com o mesmo client_id);
 *  - mensagens novas chegam pelo Reverb e, se o tempo real falhar, por atualização periódica.
 */
const props = defineProps<{ sessionId: number; meId: number }>();

interface Message {
    id: number | null;
    client_id: string | null;
    sender: { id: number; name: string | null };
    body: string | null;
    attachment: { name: string; size: number } | null;
    created_at: string;
    status?: 'sending' | 'failed';
    file?: File | null;
}

const messages = ref<Message[]>([]);
const text = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const error = ref<string | null>(null);

function lastId(): number {
    return messages.value.reduce((max, m) => Math.max(max, m.id ?? 0), 0);
}

function merge(incoming: Message[]): void {
    for (const message of incoming) {
        const index = messages.value.findIndex((m) => (m.id !== null && m.id === message.id) || (message.client_id && m.client_id === message.client_id));
        if (index >= 0) {
            messages.value[index] = message;
        } else {
            messages.value.push(message);
        }
    }
    void nextTick(() => list.value?.scrollTo({ top: list.value.scrollHeight, behavior: 'smooth' }));
}

async function load(): Promise<void> {
    const after = lastId();
    const { data } = await api.get<{ data: Message[] }>(`/sessions/${props.sessionId}/messages${after ? `?after=${after}` : ''}`);
    merge(data);
}

async function send(message: Message): Promise<void> {
    message.status = 'sending';
    error.value = null;
    const payload = new FormData();
    if (message.body) {
        payload.append('body', message.body);
    }
    if (message.file) {
        payload.append('attachment', message.file);
    }
    payload.append('client_id', message.client_id ?? '');

    try {
        const { data } = await api.post<{ data: Message }>(`/sessions/${props.sessionId}/messages`, payload);
        merge([data]);
    } catch (e) {
        message.status = 'failed';
        if (e instanceof ApiError && e.status < 500) {
            error.value = Object.values(e.fieldErrors())[0] ?? e.message;
            messages.value = messages.value.filter((m) => m !== message);
        }
    }
}

function submit(file: File | null = null): void {
    const body = text.value.trim();
    if (!body && !file) {
        return;
    }
    const message: Message = {
        id: null,
        client_id: crypto.randomUUID(),
        sender: { id: props.meId, name: null },
        body: body || null,
        attachment: file ? { name: file.name, size: file.size } : null,
        created_at: new Date().toISOString(),
        file,
    };
    messages.value.push(message);
    text.value = '';
    void nextTick(() => list.value?.scrollTo({ top: list.value.scrollHeight }));
    void send(message);
}

function onFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file) {
        submit(file);
    }
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

async function openAttachment(message: Message): Promise<void> {
    if (!message.id) {
        return;
    }
    const { data } = await api.get<{ data: { url: string } }>(`/messages/${message.id}/attachment`);
    window.open(data.url, '_blank', 'noopener');
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString('pt-AO', { hour: '2-digit', minute: '2-digit' });
}

function size(bytes: number): string {
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

usePrivateChannel(`session.${props.sessionId}`, {
    'message.sent': (payload: { message: Message }) => merge([payload.message]),
});
usePolling(load, 10000);
onMounted(load);
</script>

<template>
    <section class="card flex flex-col p-0">
        <div ref="list" class="flex max-h-[55dvh] min-h-48 flex-col gap-2 overflow-y-auto p-4" aria-live="polite">
            <p v-if="messages.length === 0" class="m-auto text-caption text-ink-muted">Ainda não há mensagens. Diga olá!</p>
            <div
                v-for="(message, i) in messages"
                :key="message.id ?? message.client_id ?? i"
                class="max-w-[85%] rounded-card px-3 py-2"
                :class="message.sender.id === meId ? 'self-end bg-orange-500 text-white' : 'self-start bg-surface-muted text-ink'"
            >
                <p v-if="message.body" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                <button
                    v-if="message.attachment"
                    type="button"
                    class="mt-1 flex items-center gap-2 rounded-control bg-white/90 px-3 py-2 text-left text-caption text-ink"
                    :disabled="!message.id"
                    @click="openAttachment(message)"
                >
                    <AppIcon name="download" :size="18" class="text-orange-600" />
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">{{ message.attachment.name }}</span>
                        <span class="text-ink-muted">{{ size(message.attachment.size) }} · PDF</span>
                    </span>
                </button>
                <p class="mt-1 text-right text-[0.6875rem]" :class="message.sender.id === meId ? 'text-white/80' : 'text-ink-subtle'">
                    <template v-if="message.status === 'sending'">a enviar…</template>
                    <button v-else-if="message.status === 'failed'" type="button" class="font-semibold underline" @click="send(message)">não enviada · tentar de novo</button>
                    <template v-else>{{ time(message.created_at) }}</template>
                </p>
            </div>
        </div>

        <p v-if="error" class="px-4 text-caption text-danger" role="alert">{{ error }}</p>

        <form class="flex items-end gap-2 border-t border-border p-3" @submit.prevent="submit()">
            <input ref="fileInput" type="file" accept="application/pdf" class="hidden" @change="onFile" />
            <button type="button" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-navy-50 text-navy-900" aria-label="Anexar PDF" @click="fileInput?.click()">
                <AppIcon name="paperclip" :size="20" />
            </button>
            <textarea
                v-model="text"
                rows="1"
                class="input max-h-32 min-h-11 flex-1 resize-none py-2.5"
                placeholder="Escreva uma mensagem"
                maxlength="2000"
                @keydown.enter.exact.prevent="submit()"
            />
            <button type="submit" class="btn-primary size-11 min-h-11 shrink-0 rounded-full px-0" aria-label="Enviar" :disabled="!text.trim()">
                <AppIcon name="send" :size="20" />
            </button>
        </form>
    </section>
</template>
