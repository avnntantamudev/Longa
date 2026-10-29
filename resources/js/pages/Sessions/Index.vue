<script setup lang="ts">
import { api } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import type { RequestState } from '@/lib/requestState';
import { stateBadge } from '@/lib/session';
import UserAvatar from '@/components/UserAvatar.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Session {
    id: number;
    title: string;
    state: RequestState;
    state_label: string;
    category: string;
    scheduled_at: string;
    neighborhood: string;
    amount: number;
    provider_net?: number;
    role: 'client' | 'provider';
    provider?: { name: string; photo_thumb_url: string | null };
    client?: { name: string; photo_thumb_url: string | null };
}

const page = usePage();
const sessions = ref<Session[] | null>(null);
const isClient = computed(() => page.props.auth.user?.roles.includes('client'));

onMounted(async () => {
    sessions.value = (await api.get<{ data: Session[] }>('/sessions')).data;
});
</script>

<template>
    <Head title="Serviços" />

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-display">Serviços</h1>
        <Link v-if="isClient" href="/pedidos/novo" class="btn-primary min-h-10 px-4 text-caption">Pedir serviço</Link>
    </div>

    <p v-if="sessions === null" class="mt-6 text-ink-muted">A carregar…</p>
    <p v-else-if="sessions.length === 0" class="card mt-6 text-ink-muted">Ainda não tem serviços marcados.</p>

    <ul v-else class="mt-6 grid gap-3">
        <li v-for="session in sessions" :key="session.id">
            <Link :href="`/servicos/${session.id}`" class="card flex items-center gap-3">
                <UserAvatar
                    :name="session.role === 'client' ? session.provider?.name : session.client?.name"
                    :src="session.role === 'client' ? session.provider?.photo_thumb_url : session.client?.photo_thumb_url"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ session.title }}</p>
                    <p class="text-caption text-ink-muted">
                        {{ when(session.scheduled_at) }} · {{ session.neighborhood }} ·
                        {{ session.role === 'client' ? `com ${session.provider?.name}` : `cliente ${session.client?.name}` }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span class="badge" :class="stateBadge(session.state)">{{ session.state_label }}</span>
                    <span class="text-caption font-semibold">{{ kwanza(session.role === 'provider' ? (session.provider_net ?? session.amount) : session.amount) }}</span>
                </div>
            </Link>
        </li>
    </ul>
</template>
