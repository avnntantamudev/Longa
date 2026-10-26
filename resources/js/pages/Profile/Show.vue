<script setup lang="ts">
import CompanyLinks from '@/components/CompanyLinks.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import SecuritySettings from '@/components/SecuritySettings.vue';
import { api } from '@/lib/api';
import type { RoleName } from '@/types/global';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Session {
    id: string;
    type: 'web' | 'api';
    device_name: string | null;
    ip: string | null;
    last_active_at: string | null;
    current: boolean;
}

const page = usePage();
const user = computed(() => page.props.auth.user);
const sessions = ref<Session[]>([]);
const trainerStatus = ref<{ completed: boolean; verification: string } | null>(null);

const roleLabels: Record<RoleName, string> = { student: 'Aluno', trainer: 'Formador', company_rh: 'Empresa (RH)', admin: 'Administrador' };
const verificationLabels: Record<string, string> = {
    unverified: 'Por verificar',
    pending: 'Verificação em análise',
    verified: 'Verificado LONGA',
    rejected: 'Verificação rejeitada',
};


function formatDate(value: string | null): string {
    return value ? new Intl.DateTimeFormat('pt-AO', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';
}

async function loadSessions(): Promise<void> {
    sessions.value = (await api.get<{ data: Session[] }>('/auth/sessions')).data;
}

async function revoke(session: Session): Promise<void> {
    await api.delete(`/auth/sessions/${session.id}`);
    await loadSessions();
}

async function logoutAll(): Promise<void> {
    if (confirm('Terminar a sessão em todos os dispositivos, incluindo este?')) {
        await api.post('/auth/logout-all');
        window.location.href = '/entrar';
    }
}

onMounted(async () => {
    await loadSessions();
    if (user.value?.roles.includes('trainer')) {
        const { data } = await api.get<{ data: { onboarding: { completed: boolean }; verification_status: string } }>('/me/trainer-profile');
        trainerStatus.value = { completed: data.onboarding.completed, verification: data.verification_status };
    }
});
</script>

<template>
    <Head title="Perfil" />

    <section v-if="user" class="card grid gap-4">
        <PhotoPicker :name="user.name" :src="user.photo_url" :required="user.roles.includes('trainer')" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-title">{{ user.name }}</p>
            <p class="text-caption text-ink-muted">{{ user.phone }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                <span v-for="role in user.roles" :key="role" class="badge badge-neutral">{{ roleLabels[role] }}</span>
            </div>
        </div>
    </section>

    <CompanyLinks />

    <SecuritySettings />

    <section v-if="trainerStatus" class="card mt-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="font-semibold">Perfil de formador</p>
                <p class="text-caption text-ink-muted">{{ trainerStatus.completed ? 'Perfil completo' : 'Faltam dados no perfil' }}</p>
            </div>
            <span class="badge" :class="trainerStatus.verification === 'verified' ? 'badge-success' : 'badge-warning'">
                {{ verificationLabels[trainerStatus.verification] }}
            </span>
        </div>
        <Link href="/onboarding/formador" class="btn-secondary mt-3 w-full">Editar perfil de formador</Link>
    </section>

    <section v-if="user?.roles.includes('admin')" class="card mt-4">
        <p class="font-semibold">Administração</p>
        <Link href="/admin/verificacoes" class="btn-secondary mt-3 w-full">Verificações pendentes</Link>
    </section>

    <h2 class="mt-8 text-title">Sessões ativas</h2>
    <ul class="mt-3 grid gap-2">
        <li v-for="session in sessions" :key="session.id" class="card flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">
                    {{ session.device_name ?? 'Dispositivo' }}
                    <span v-if="session.current" class="badge badge-success ml-1">Este dispositivo</span>
                </p>
                <p class="text-caption text-ink-muted">Última atividade: {{ formatDate(session.last_active_at) }}</p>
            </div>
            <button v-if="!session.current" type="button" class="text-caption font-semibold text-danger" @click="revoke(session)">Terminar</button>
        </li>
    </ul>

    <div class="mt-6 grid gap-3">
        <button type="button" class="btn-secondary w-full" @click="router.post('/sair')">Terminar sessão</button>
        <button type="button" class="w-full text-caption font-semibold text-danger underline" @click="logoutAll">Terminar sessão em todos os dispositivos</button>
    </div>
</template>
