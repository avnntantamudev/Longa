<script setup lang="ts">
import ChatPanel from '@/components/ChatPanel.vue';
import MiniMap from '@/components/MiniMap.vue';
import { api } from '@/lib/api';
import { kwanza, when } from '@/lib/format';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ sessionId: number }>();

interface Session {
    id: number;
    state: string;
    title: string;
    skill: string;
    scheduled_at: string;
    modality: string;
    municipality: string;
    neighborhood: string;
    latitude: number;
    longitude: number;
    amount: number;
    commission?: number;
    trainer_net?: number;
    role: 'student' | 'trainer';
    student?: { name: string | null };
    trainer?: { name: string | null; bio: string | null; years_experience: number | null; neighborhood: string | null; verified: boolean; score: number };
}

const page = usePage();
const session = ref<Session | null>(null);
const meId = computed(() => page.props.auth.user?.id ?? 0);
const counterpart = computed(() => (session.value?.role === 'student' ? session.value.trainer?.name : session.value?.student?.name) ?? '');
const initials = computed(() =>
    counterpart.value
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase(),
);

onMounted(async () => {
    session.value = (await api.get<{ data: Session }>(`/sessions/${props.sessionId}`)).data;
});
</script>

<template>
    <Head title="Aula" />

    <template v-if="session">
        <!-- Cartão do outro participante (kit: "Formador · Carlos Silva · Instructor SGME") -->
        <section class="rounded-sheet bg-navy-900 p-5 text-white">
            <div class="flex items-center gap-4">
                <span class="avatar-initials size-16 text-title">{{ initials }}</span>
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
            <p class="font-semibold">{{ session.title }}</p>
            <span class="badge badge-success mt-1">Agendada</span>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-caption">
                <div><dt class="text-ink-muted">Quando</dt><dd class="font-semibold">{{ when(session.scheduled_at) }}</dd></div>
                <div><dt class="text-ink-muted">Onde</dt><dd class="font-semibold">{{ session.modality === 'online' ? 'Online' : `${session.neighborhood}, ${session.municipality}` }}</dd></div>
                <div><dt class="text-ink-muted">Valor</dt><dd class="font-semibold">{{ kwanza(session.amount) }}</dd></div>
                <div v-if="session.role === 'trainer'"><dt class="text-ink-muted">Recebe</dt><dd class="font-semibold">{{ kwanza(session.trainer_net ?? 0) }}</dd></div>
            </dl>
            <div v-if="session.modality !== 'online'" class="mt-3">
                <MiniMap :lat="session.latitude" :lng="session.longitude" :approximate="false" :label="session.neighborhood" />
            </div>
            <p class="mt-3 text-caption text-ink-muted">A confirmação da aula por QR e o pagamento chegam na próxima versão.</p>
        </section>

        <h2 class="mt-6 text-title">Chat da aula</h2>
        <p class="mb-3 text-caption text-ink-muted">Combine os detalhes aqui. Os contactos pessoais ficam escondidos nesta fase.</p>
        <ChatPanel :session-id="session.id" :me-id="meId" />
    </template>

    <p v-else class="text-ink-muted">A carregar…</p>
</template>
