<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import InstallPrompt from '@/components/InstallPrompt.vue';
import NetworkStatus from '@/components/NetworkStatus.vue';
import PinPrompt from '@/components/PinPrompt.vue';
import UpdatePrompt from '@/components/UpdatePrompt.vue';
import { navigationFor, type NavItem } from '@/lib/navigation';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const navigation = computed(() => navigationFor(user.value?.roles));

function isActive(item: NavItem): boolean {
    const path = page.url.split('?')[0] ?? '/';
    return item.href === '/' ? path === '/' : path.startsWith(item.href);
}
</script>

<template>
    <div class="min-h-dvh md:flex">
        <!-- Barra lateral (desktop) -->
        <aside class="hidden w-64 shrink-0 flex-col bg-navy-900 text-white md:flex">
            <div class="px-6 py-6">
                <BrandLogo :size="36" />
                <p class="mt-1 text-caption text-navy-200">marketplace • formadores</p>
            </div>
            <nav aria-label="Navegação principal" class="flex flex-col gap-1 px-3">
                <template v-for="item in navigation" :key="item.href">
                    <Link
                        v-if="item.available"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-control px-3 py-3 font-medium transition-colors"
                        :class="isActive(item) ? 'bg-white/10 text-orange-400' : 'text-navy-100 hover:bg-white/5'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <AppIcon :name="item.icon" />
                        {{ item.label }}
                    </Link>
                    <span
                        v-else
                        aria-disabled="true"
                        title="Brevemente"
                        class="flex cursor-not-allowed items-center gap-3 rounded-control px-3 py-3 font-medium text-navy-300/60"
                    >
                        <AppIcon :name="item.icon" />
                        {{ item.label }}
                    </span>
                </template>
            </nav>
            <div class="mt-auto p-6">
                <Link v-if="!user" href="/entrar" class="btn-primary w-full">Entrar</Link>
                <Link v-else href="/perfil" class="flex items-center gap-3">
                    <UserAvatar :name="user.name" :src="user.photo_thumb_url" :size="36" />
                    <span class="truncate text-caption text-navy-200">{{ user.name ?? user.phone }}</span>
                </Link>
            </div>
        </aside>

        <div class="flex min-h-dvh flex-1 flex-col">
            <!-- Cabeçalho (telemóvel) -->
            <header class="pt-safe sticky top-0 z-20 bg-navy-900 text-white md:hidden">
                <div class="flex h-14 items-center justify-between px-4">
                    <BrandLogo :size="30" />
                    <Link v-if="!user" href="/entrar" class="rounded-full bg-orange-500 px-4 py-1.5 text-caption font-semibold text-white">Entrar</Link>
                </div>
            </header>

            <!-- Espaço inferior reservado para a barra de navegação no telemóvel -->
            <main class="mx-auto w-full max-w-3xl flex-1 px-4 pb-28 pt-4 md:px-8 md:pb-10 md:pt-8">
                <Link
                    v-if="user && user.roles.includes('trainer') && !user.photo_url && !page.url.startsWith('/onboarding')"
                    href="/perfil"
                    class="mb-4 flex items-center gap-3 rounded-control bg-orange-50 p-3 text-caption text-orange-600"
                    role="status"
                >
                    <AppIcon name="user" />
                    <span><strong>Carregue a sua foto de perfil.</strong> Sem foto deixa de receber pedidos TRIM TRIM.</span>
                </Link>
                <slot />
            </main>
        </div>

        <!-- Barra inferior (telemóvel) -->
        <nav
            aria-label="Navegação principal"
            class="pb-safe fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface shadow-nav md:hidden"
        >
            <ul class="grid" :style="{ gridTemplateColumns: `repeat(${navigation.length}, minmax(0, 1fr))` }">
                <li v-for="item in navigation" :key="item.href">
                    <Link
                        v-if="item.available"
                        :href="item.href"
                        class="flex h-16 flex-col items-center justify-center gap-1 text-[0.6875rem] font-medium"
                        :class="isActive(item) ? 'text-orange-500' : 'text-ink-muted'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <AppIcon :name="item.icon" />
                        {{ item.label }}
                    </Link>
                    <span
                        v-else
                        aria-disabled="true"
                        class="flex h-16 flex-col items-center justify-center gap-1 text-[0.6875rem] font-medium text-ink-subtle/60"
                    >
                        <AppIcon :name="item.icon" />
                        {{ item.label }}
                    </span>
                </li>
            </ul>
        </nav>

        <NetworkStatus />
        <InstallPrompt />
        <UpdatePrompt />
        <PinPrompt />
    </div>
</template>
