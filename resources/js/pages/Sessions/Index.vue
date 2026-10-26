<script setup lang="ts">
import { api } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { stateBadge } from '@/lib/session';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Session {
    id: number;
    title: string;
    state: string;
    state_label: string;
    skill: string;
    scheduled_at: string;
    neighborhood: string;
    amount: number;
    trainer_net?: number;
    role: 'student' | 'trainer';
    trainer?: { name: string };
    student?: { name: string };
}

const page = usePage();
const sessions = ref<Session[] | null>(null);
const isStudent = computed(() => page.props.auth.user?.roles.includes('student'));

onMounted(async () => {
    sessions.value = (await api.get<{ data: Session[] }>('/sessions')).data;
});
</script>

<template>
    <Head title="Aulas" />

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-display">Aulas</h1>
        <Link v-if="isStudent" href="/pedidos/novo" class="btn-primary min-h-10 px-4 text-caption">Pedir aula</Link>
    </div>

    <p v-if="sessions === null" class="mt-6 text-ink-muted">A carregar…</p>
    <p v-else-if="sessions.length === 0" class="card mt-6 text-ink-muted">Ainda não tem aulas marcadas.</p>

    <ul v-else class="mt-6 grid gap-3">
        <li v-for="session in sessions" :key="session.id">
            <Link :href="`/aulas/${session.id}`" class="card flex items-center gap-3">
                <span class="avatar-initials">{{ (session.role === 'student' ? session.trainer?.name : session.student?.name)?.slice(0, 2).toUpperCase() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ session.title }}</p>
                    <p class="text-caption text-ink-muted">
                        {{ when(session.scheduled_at) }} · {{ session.neighborhood }} ·
                        {{ session.role === 'student' ? `com ${session.trainer?.name}` : `aluno ${session.student?.name}` }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span class="badge" :class="stateBadge(session.state)">{{ session.state_label }}</span>
                    <span class="text-caption font-semibold">{{ kwanza(session.role === 'trainer' ? (session.trainer_net ?? session.amount) : session.amount) }}</span>
                </div>
            </Link>
        </li>
    </ul>
</template>
