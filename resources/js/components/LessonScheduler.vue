<script setup lang="ts">
/**
 * Datas das aulas de um pacote: o cliente escolhe os dias da semana, a hora, o início e o nº de semanas; a lista
 * gera-se sozinha e cada aula pode ser mudada ou retirada (ou acrescentada). Devolve datas "YYYY-MM-DDTHH:mm" locais.
 */
import { computed, reactive, watch } from 'vue';

const props = defineProps<{ modelValue: string[]; minSessions: number; maxSessions: number; maxWeeks: number; error?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const days = [
    { value: 1, label: 'Seg' },
    { value: 2, label: 'Ter' },
    { value: 3, label: 'Qua' },
    { value: 4, label: 'Qui' },
    { value: 5, label: 'Sex' },
    { value: 6, label: 'Sáb' },
    { value: 0, label: 'Dom' },
];

const pad = (n: number) => String(n).padStart(2, '0');
const dateOnly = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const tomorrow = new Date();
tomorrow.setDate(tomorrow.getDate() + 1);

const pattern = reactive({ weekdays: [1, 3, 5] as number[], time: '09:00', start: dateOnly(tomorrow), weeks: 1 });
const minStart = dateOnly(tomorrow);
const minLocal = `${minStart}T00:00`;

function toggleDay(day: number): void {
    pattern.weekdays = pattern.weekdays.includes(day) ? pattern.weekdays.filter((d) => d !== day) : [...pattern.weekdays, day];
}

/** Gera as aulas: os dias escolhidos durante N semanas a partir do início, à hora escolhida (no máximo o limite). */
function generate(): void {
    const result: string[] = [];
    const start = new Date(`${pattern.start}T00:00`);
    for (let i = 0; i < pattern.weeks * 7 && result.length < props.maxSessions; i++) {
        const day = new Date(start);
        day.setDate(start.getDate() + i);
        if (pattern.weekdays.includes(day.getDay())) result.push(`${dateOnly(day)}T${pattern.time}`);
    }
    emit('update:modelValue', result);
}

watch(pattern, generate, { deep: true, immediate: true });

const lessons = computed(() => [...props.modelValue].sort());

function update(index: number, value: string): void {
    const next = [...lessons.value];
    next[index] = value;
    emit('update:modelValue', next.filter(Boolean));
}

function remove(index: number): void {
    emit('update:modelValue', lessons.value.filter((_, i) => i !== index));
}

function add(): void {
    const last = lessons.value.at(-1);
    const base = last ? new Date(last) : new Date(`${pattern.start}T${pattern.time}`);
    if (last) base.setDate(base.getDate() + 1);
    emit('update:modelValue', [...lessons.value, `${dateOnly(base)}T${pad(base.getHours())}:${pad(base.getMinutes())}`]);
}

const weekday = (local: string) => new Date(local).toLocaleDateString('pt-PT', { weekday: 'short' });
</script>

<template>
    <div class="grid gap-3" data-testid="lesson-scheduler">
        <fieldset class="grid gap-2">
            <legend class="mb-1 text-caption font-semibold">Em que dias?</legend>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="d in days"
                    :key="d.value"
                    type="button"
                    class="chip"
                    :class="{ 'chip-active': pattern.weekdays.includes(d.value) }"
                    :aria-pressed="pattern.weekdays.includes(d.value)"
                    @click="toggleDay(d.value)"
                >
                    {{ d.label }}
                </button>
            </div>
        </fieldset>

        <div class="grid grid-cols-3 gap-3">
            <label class="grid gap-1 text-caption font-semibold">
                Hora
                <input v-model="pattern.time" type="time" class="input" step="900" />
            </label>
            <label class="grid gap-1 text-caption font-semibold">
                Começa a
                <input v-model="pattern.start" type="date" class="input" :min="minStart" />
            </label>
            <label class="grid gap-1 text-caption font-semibold">
                Semanas
                <select v-model.number="pattern.weeks" class="input">
                    <option v-for="w in maxWeeks" :key="w" :value="w">{{ w }}</option>
                </select>
            </label>
        </div>

        <div class="grid gap-2">
            <p class="text-caption font-semibold">{{ lessons.length }} {{ lessons.length === 1 ? 'aula' : 'aulas' }} (pode mudar ou retirar cada uma)</p>
            <ul class="grid gap-2">
                <li v-for="(lesson, i) in lessons" :key="`${i}-${lesson}`" class="flex items-center gap-2">
                    <span class="w-10 shrink-0 text-caption text-ink-muted">{{ weekday(lesson) }}</span>
                    <input :value="lesson" type="datetime-local" class="input" :min="minLocal" :aria-label="`Aula ${i + 1}`" @change="update(i, ($event.target as HTMLInputElement).value)" />
                    <button type="button" class="chip shrink-0" :aria-label="`Retirar a aula ${i + 1}`" @click="remove(i)">✕</button>
                </li>
            </ul>
            <button v-if="lessons.length < maxSessions" type="button" class="btn-outline w-full" @click="add">Acrescentar uma aula</button>
            <p v-if="lessons.length < minSessions" class="text-caption text-danger">Escolha pelo menos {{ minSessions }} aulas.</p>
            <p v-if="error" class="text-caption text-danger">{{ error }}</p>
            <p class="text-caption text-ink-muted">Até {{ maxSessions }} aulas em {{ maxWeeks }} semanas, a partir de amanhã.</p>
        </div>
    </div>
</template>
