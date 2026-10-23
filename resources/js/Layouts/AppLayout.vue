<script setup lang="ts">
import AppIcon from '@/Components/AppIcon.vue';
import InstallPrompt from '@/Components/InstallPrompt.vue';
import NetworkStatus from '@/Components/NetworkStatus.vue';
import UpdatePrompt from '@/Components/UpdatePrompt.vue';
import { mainNavigation, type NavItem } from '@/lib/navigation';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

function isActive(item: NavItem): boolean {
    const path = page.url.split('?')[0] ?? '/';
    return item.href === '/' ? path === '/' : path.startsWith(item.href);
}
</script>

<template>
    <div class="min-h-dvh md:flex">
        <!-- Barra lateral (desktop) -->
        <aside class="hidden w-64 shrink-0 flex-col bg-navy-900 text-white md:flex">
            <div class="flex items-center gap-3 px-6 py-6">
                <img src="/icons/icon-192.png" alt="" class="size-9 rounded-control" />
                <span class="text-title tracking-wide">LONGA</span>
            </div>
            <nav aria-label="Navegação principal" class="flex flex-col gap-1 px-3">
                <template v-for="item in mainNavigation" :key="item.href">
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
        </aside>

        <div class="flex min-h-dvh flex-1 flex-col">
            <!-- Cabeçalho (telemóvel) -->
            <header class="pt-safe sticky top-0 z-20 bg-navy-900 text-white md:hidden">
                <div class="flex h-14 items-center gap-2 px-4">
                    <img src="/icons/icon-192.png" alt="" class="size-8 rounded-[0.5rem]" />
                    <span class="text-title tracking-wide">LONGA</span>
                </div>
            </header>

            <!-- Espaço inferior reservado para a barra de navegação no telemóvel -->
            <main class="mx-auto w-full max-w-3xl flex-1 px-4 pb-28 pt-4 md:px-8 md:pb-10 md:pt-8">
                <slot />
            </main>
        </div>

        <!-- Barra inferior (telemóvel) -->
        <nav
            aria-label="Navegação principal"
            class="pb-safe fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface shadow-nav md:hidden"
        >
            <ul class="grid grid-cols-5">
                <li v-for="item in mainNavigation" :key="item.href">
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
    </div>
</template>
