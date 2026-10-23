import type { IconName } from '@/components/AppIcon.vue';
import type { RoleName } from '@/types/global';

export interface NavItem {
    label: string;
    href: string;
    icon: IconName;
    /** false enquanto o módulo correspondente não existir (aparece desativado) */
    available: boolean;
}

/**
 * Navegação principal: barra inferior no telemóvel, barra lateral no desktop.
 * Depende dos perfis: o aluno pede aulas, o formador recebe pedidos TRIM TRIM.
 */
export function navigationFor(roles: RoleName[] = []): NavItem[] {
    const items: NavItem[] = [{ label: 'Início', href: '/', icon: 'home', available: true }];

    if (roles.includes('student')) {
        items.push({ label: 'Pedir aula', href: '/pedidos/novo', icon: 'search', available: true });
    }
    if (roles.includes('trainer')) {
        items.push({ label: 'TRIM TRIM', href: '/formador/ofertas', icon: 'bell', available: true });
    }
    if (roles.length === 0) {
        items.push({ label: 'Explorar', href: '/explorar', icon: 'search', available: false });
    }

    items.push(
        { label: 'Aulas', href: '/aulas', icon: 'calendar', available: roles.length > 0 },
        { label: 'Perfil', href: '/perfil', icon: 'user', available: true },
    );

    return items;
}
