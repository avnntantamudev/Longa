<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import { useNetworkState } from '@/composables/useNetworkState';

const { online, isSending, hasFailed, failureMessage, retry, dismiss } = useNetworkState();
</script>

<template>
    <!-- Sem ligação: faixa fixa no topo -->
    <Transition
        enter-from-class="-translate-y-full"
        leave-to-class="-translate-y-full"
        enter-active-class="transition-transform duration-200"
        leave-active-class="transition-transform duration-200"
    >
        <div
            v-if="!online"
            role="status"
            class="pt-safe fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-2 bg-navy-900 px-4 py-2 text-caption font-medium text-white"
        >
            <AppIcon name="wifi-off" :size="16" />
            Sem ligação. Algumas ações ficam indisponíveis até voltar a ter rede.
        </div>
    </Transition>

    <!-- A enviar: indicador discreto -->
    <div
        v-if="isSending && !hasFailed"
        role="status"
        aria-live="polite"
        class="fixed inset-x-0 bottom-24 z-40 mx-auto flex w-max items-center gap-2 rounded-full bg-navy-900 px-4 py-2 text-caption font-medium text-white shadow-raised md:bottom-6"
    >
        <span class="size-3 animate-spin rounded-full border-2 border-white/40 border-t-white" />
        A enviar…
    </div>

    <!-- Falhou: aviso com "tentar de novo" -->
    <div
        v-if="hasFailed"
        role="alert"
        class="fixed inset-x-4 bottom-24 z-40 mx-auto flex max-w-md items-center gap-3 rounded-card bg-surface p-3 shadow-raised ring-1 ring-danger/20 md:bottom-6"
    >
        <p class="flex-1 text-caption text-ink">{{ failureMessage }}</p>
        <button type="button" class="btn-primary min-h-10 px-3 text-caption" @click="retry">
            <AppIcon name="refresh" :size="16" />
            Tentar de novo
        </button>
        <button type="button" class="p-1 text-ink-subtle hover:text-ink" aria-label="Fechar aviso" @click="dismiss">
            <AppIcon name="close" :size="18" />
        </button>
    </div>
</template>
