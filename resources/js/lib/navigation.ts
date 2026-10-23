import type { IconName } from '@/components/AppIcon.vue';

export interface NavItem {
    label: string;
    href: string;
    icon: IconName;
    /** false enquanto o módulo correspondente não existir (aparece desativado) */
    available: boolean;
}

// Navegação principal (kit, perfil aluno): barra inferior no telemóvel, barra lateral no desktop.
// Formador e RH terão navegação própria (Pedidos, Carteira, Formações) quando os perfis existirem.
export const mainNavigation: NavItem[] = [
    { label: 'Início', href: '/', icon: 'home', available: true },
    { label: 'Explorar', href: '/explorar', icon: 'search', available: false },
    { label: 'Aulas', href: '/aulas', icon: 'calendar', available: false },
    { label: 'Mensagens', href: '/mensagens', icon: 'chat', available: false },
    { label: 'Perfil', href: '/perfil', icon: 'user', available: true },
];
