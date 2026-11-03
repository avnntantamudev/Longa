<script setup lang="ts">
/**
 * Escolha de um serviço com pesquisa (sem acentos, várias palavras). Mostra a lista agrupada;
 * depois de escolhido, mostra só o serviço com a opção de mudar.
 */
import AppIcon from '@/components/AppIcon.vue';
import { matchesSearch } from '@/lib/search';
import type { Category } from '@/types/global';
import { computed, nextTick, ref } from 'vue';

const props = defineProps<{ categories: Category[]; modelValue: number | null; id?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null]; change: [] }>();

const query = ref('');
const input = ref<HTMLInputElement | null>(null);

const selected = computed(() => props.categories.find((c) => c.id === props.modelValue) ?? null);

const groups = computed(() => {
    const result: Record<string, Category[]> = {};
    for (const c of props.categories) {
        if (!matchesSearch(query.value, c.name, c.path)) continue;
        (result[c.group ?? 'Outros'] ??= []).push(c);
    }
    return result;
});

const hasResults = computed(() => Object.keys(groups.value).length > 0);

function choose(category: Category): void {
    emit('update:modelValue', category.id);
    emit('change');
    query.value = '';
}

async function change(): Promise<void> {
    emit('update:modelValue', null);
    emit('change');
    await nextTick();
    input.value?.focus();
}
</script>

<template>
    <div v-if="selected" class="card flex items-center justify-between gap-3" data-testid="category-selected">
        <div class="min-w-0">
            <p class="truncate font-semibold">{{ selected.name }}</p>
            <p v-if="selected.group" class="truncate text-caption text-ink-muted">{{ selected.group }}</p>
        </div>
        <button type="button" class="btn-outline shrink-0" @click="change">Mudar</button>
    </div>

    <div v-else class="grid gap-2">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-muted"><AppIcon name="search" :size="18" /></span>
            <input
                :id="id"
                ref="input"
                v-model="query"
                type="search"
                class="input pl-10"
                placeholder="Pesquisar serviço (ex.: canalizador, explicações)"
                autocomplete="off"
                enterkeyhint="search"
            />
        </div>

        <div class="max-h-80 overflow-y-auto rounded-lg border border-border" role="listbox" :aria-labelledby="id">
            <section v-for="(group, groupName) in groups" :key="groupName">
                <p class="sticky top-0 bg-surface-muted px-3 py-1 text-caption font-semibold text-ink-muted">{{ groupName }}</p>
                <button
                    v-for="c in group"
                    :key="c.id"
                    type="button"
                    role="option"
                    :aria-selected="false"
                    class="block w-full px-3 py-3 text-left hover:bg-surface-muted focus:bg-surface-muted focus:outline-none"
                    @click="choose(c)"
                >
                    {{ c.name }}
                </button>
            </section>
            <p v-if="!hasResults" class="px-3 py-4 text-caption text-ink-muted">Nenhum serviço encontrado para "{{ query }}". Tente outra palavra.</p>
        </div>
    </div>
</template>
