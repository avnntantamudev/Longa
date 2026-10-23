import type { IconName } from '@/components/AppIcon.vue';

export interface NavItem {
    label: string;
    href: string;
    icon: IconName;
    /** false enquanto o módulo correspondente não existir (aparece desativado) */
    available: boolean;
}

// Navegação principal: barra inferior no telemóvel, barra lateral no desktop
export const mainNavigation: NavItem[] = [
    { label: 'Início', href: '/', icon: 'home', available: true },
    { label: 'Explorar', href: '/explorar', icon: 'search', available: false },
    { label: 'Aulas', href: '/aulas', icon: 'calendar', available: false },
    { label: 'Carteira', href: '/carteira', icon: 'wallet', available: false },
    { label: 'Perfil', href: '/perfil', icon: 'user', available: false },
];
