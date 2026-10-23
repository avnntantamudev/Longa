<script setup lang="ts">
import { useServiceWorker } from '@/composables/useServiceWorker';

const { needRefresh, update, dismiss } = useServiceWorker();
</script>

<template>
    <div
        v-if="needRefresh"
        role="status"
        class="card fixed inset-x-4 top-4 z-50 mx-auto flex max-w-md items-center gap-3"
    >
        <p class="flex-1 text-caption">Há uma nova versão do LONGA disponível.</p>
        <button type="button" class="btn-secondary min-h-10 px-3 text-caption" @click="dismiss">Depois</button>
        <button type="button" class="btn-primary min-h-10 px-3 text-caption" @click="update">Atualizar</button>
    </div>
</template>
