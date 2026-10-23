<script setup lang="ts">
import { computed } from 'vue';

/** Anel de contagem do TRIM TRIM (kit: "60s · Tempo para aceitar"). */
const props = withDefaults(defineProps<{ seconds: number; total?: number; label?: string }>(), { total: 60, label: 'Tempo para aceitar' });

const radius = 44;
const circumference = 2 * Math.PI * radius;
const offset = computed(() => circumference * (1 - Math.min(1, props.seconds / props.total)));
const urgent = computed(() => props.seconds <= 10);
</script>

<template>
    <div class="flex flex-col items-center gap-2" role="timer" :aria-label="`${seconds} segundos`">
        <div class="relative size-28">
            <svg viewBox="0 0 100 100" class="size-full -rotate-90">
                <circle cx="50" cy="50" :r="radius" fill="none" stroke="currentColor" stroke-width="7" class="text-white/15" />
                <circle
                    cx="50"
                    cy="50"
                    :r="radius"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="7"
                    stroke-linecap="round"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="offset"
                    class="transition-[stroke-dashoffset] duration-300"
                    :class="urgent ? 'text-danger' : 'text-orange-500'"
                />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-3xl font-bold tabular-nums" :class="urgent ? 'text-danger' : 'text-orange-400'">
                {{ seconds }}s
            </span>
        </div>
        <span class="text-caption text-navy-200">{{ label }}</span>
    </div>
</template>
