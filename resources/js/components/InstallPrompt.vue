<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import { useInstallPrompt } from '@/composables/useInstallPrompt';

const { visible, canPrompt, showIosHint, install, dismiss } = useInstallPrompt();
</script>

<template>
    <aside
        v-if="visible"
        aria-label="Instalar a aplicação"
        class="card fixed inset-x-4 bottom-24 z-30 mx-auto flex max-w-md items-start gap-3 md:bottom-6"
    >
        <img src="/icons/icon-192.png" alt="" class="size-11 rounded-control" />
        <div class="flex-1">
            <p class="font-semibold">Adicionar o Garimpo ao ecrã inicial</p>
            <p v-if="canPrompt" class="text-caption text-ink-muted">Abre mais depressa e funciona com pouca rede.</p>
            <p v-else-if="showIosHint" class="text-caption text-ink-muted">
                Toque em <AppIcon name="share" :size="14" class="inline align-text-bottom" /> Partilhar e depois em
                <strong>Adicionar ao ecrã principal</strong>.
            </p>
            <button v-if="canPrompt" type="button" class="btn-primary mt-3 min-h-10 w-full text-caption" @click="install">
                <AppIcon name="download" :size="16" />
                Instalar
            </button>
        </div>
        <button type="button" class="p-1 text-ink-subtle hover:text-ink" aria-label="Agora não" @click="dismiss">
            <AppIcon name="close" :size="18" />
        </button>
    </aside>
</template>
