<script setup lang="ts">
import UserAvatar from '@/components/UserAvatar.vue';
import { api, ApiError } from '@/lib/api';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Escolher ou tirar a foto de perfil. Recorta ao centro em quadrado e reduz para 1024 px no telemóvel
 * antes de enviar (poupa dados); o servidor volta a processar e remove metadados.
 */
const props = withDefaults(defineProps<{ name?: string | null; src?: string | null; required?: boolean }>(), { name: null, src: null, required: false });
const emit = defineEmits<{ changed: [url: string | null] }>();

const current = ref<string | null>(props.src);
const busy = ref(false);
const error = ref('');
const input = ref<HTMLInputElement | null>(null);

async function squareJpeg(file: File): Promise<Blob> {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const side = Math.min(bitmap.width, bitmap.height);
    const target = Math.min(1024, side);
    const canvas = document.createElement('canvas');
    canvas.width = target;
    canvas.height = target;
    canvas.getContext('2d')?.drawImage(bitmap, (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side, 0, 0, target, target);
    bitmap.close();
    return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('canvas'))), 'image/jpeg', 0.9));
}

async function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    (event.target as HTMLInputElement).value = '';
    if (!file) return;
    error.value = '';
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        error.value = 'Escolha uma foto JPG, PNG ou WebP.';
        return;
    }

    busy.value = true;
    try {
        const body = new FormData();
        // Se o browser não conseguir processar (formatos raros), envia o original e o servidor trata
        const blob = await squareJpeg(file).catch(() => file);
        body.append('photo', blob, 'foto.jpg');
        const { data } = await api.post<{ data: { photo_url: string | null } }>('/me/photo', body);
        current.value = data.photo_url;
        emit('changed', data.photo_url);
        router.reload({ only: ['auth'] }); // atualiza a foto no menu
    } catch (e) {
        error.value = e instanceof ApiError ? (Object.values(e.fieldErrors())[0] ?? e.message) : 'Sem ligação. Tente de novo.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex items-center gap-4">
        <UserAvatar :name="name" :src="current" :size="80" />
        <div class="grid gap-1">
            <button type="button" class="btn-secondary min-h-10 px-4 text-caption" :disabled="busy" @click="input?.click()">
                {{ busy ? 'A enviar…' : current ? 'Trocar foto' : 'Carregar foto' }}
            </button>
            <p class="text-caption text-ink-muted">
                {{ required ? 'Obrigatória para formadores: rosto visível, sem óculos escuros.' : 'Uma foto ajuda a criar confiança.' }}
            </p>
            <p v-if="error" class="text-caption text-danger" role="alert">{{ error }}</p>
        </div>
        <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile" />
    </div>
</template>
