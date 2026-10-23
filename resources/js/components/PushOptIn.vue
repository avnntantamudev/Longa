<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { ref } from 'vue';

/** Convite para ativar notificações (necessárias para receber o TRIM TRIM com a app fechada). */
defineProps<{ title?: string; description?: string }>();

const { state, canEnable, enable } = usePushNotifications();
const busy = ref(false);

async function activate(): Promise<void> {
    busy.value = true;
    try {
        await enable();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="state !== 'subscribed' && state !== 'unsupported'" class="card flex items-start gap-3">
        <span class="mt-0.5 text-orange-500"><AppIcon name="bell" /></span>
        <div class="flex-1">
            <p class="font-semibold">{{ title ?? 'Ative as notificações' }}</p>
            <p class="text-caption text-ink-muted">
                <template v-if="state === 'needs-install'">No iPhone, instale primeiro a LONGA no ecrã principal (Partilhar → Adicionar ao ecrã principal).</template>
                <template v-else-if="state === 'denied'">As notificações estão bloqueadas. Ative-as nas definições do navegador.</template>
                <template v-else>{{ description ?? 'Para receber pedidos mesmo com a app fechada.' }}</template>
            </p>
            <button v-if="canEnable" type="button" class="btn-primary mt-3 min-h-10 text-caption" :disabled="busy" @click="activate">
                {{ busy ? 'A ativar…' : 'Ativar notificações' }}
            </button>
        </div>
    </div>
</template>
