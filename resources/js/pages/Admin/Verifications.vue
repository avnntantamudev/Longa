<script setup lang="ts">
import UserAvatar from '@/components/UserAvatar.vue';
import { api } from '@/lib/api';
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

interface Verification {
    id: number;
    status: string;
    document_number_masked: string | null;
    documents_count: number;
    created_at: string;
    user?: { id: number; name: string | null; photo_url: string | null };
}

const items = ref<Verification[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    items.value = (await api.get<{ data: Verification[] }>('/admin/verifications')).data;
    loading.value = false;
}

async function approve(item: Verification): Promise<void> {
    await api.post(`/admin/verifications/${item.id}/approve`);
    await load();
}

async function reject(item: Verification): Promise<void> {
    const reason = prompt('Motivo da rejeição (o técnico vai vê-lo):');
    if (reason && reason.trim().length >= 5) {
        await api.post(`/admin/verifications/${item.id}/reject`, { reason: reason.trim() });
        await load();
    }
}

async function rejectPhoto(item: Verification): Promise<void> {
    const reason = prompt('Porque remove a foto? (o técnico vai ver o motivo)', 'Não se vê bem o rosto');
    if (item.user && reason && reason.trim().length >= 5) {
        await api.post(`/admin/users/${item.user.id}/photo/reject`, { reason: reason.trim() });
        await load();
    }
}

onMounted(load);
</script>

<template>
    <Head title="Verificações pendentes" />

    <h1 class="text-display">Verificações pendentes</h1>
    <p class="mt-1 text-ink-muted">O nº do BI aparece sempre mascarado.</p>

    <p v-if="loading" class="mt-6 text-ink-muted">A carregar…</p>
    <p v-else-if="items.length === 0" class="card mt-6 text-ink-muted">Não há verificações pendentes.</p>

    <ul v-else class="mt-6 grid gap-3">
        <li v-for="item in items" :key="item.id" class="card">
            <div class="flex items-center gap-3">
                <UserAvatar :name="item.user?.name" :src="item.user?.photo_url" :size="56" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ item.user?.name }}</p>
                    <p class="text-caption text-ink-muted">BI {{ item.document_number_masked }} · {{ item.documents_count }} documento(s)</p>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="btn-primary min-h-10" @click="approve(item)">Aprovar</button>
                <button type="button" class="btn-outline min-h-10" @click="reject(item)">Rejeitar</button>
                <button v-if="item.user?.photo_url" type="button" class="text-caption font-semibold text-danger" @click="rejectPhoto(item)">Foto imprópria</button>
            </div>
        </li>
    </ul>
</template>
