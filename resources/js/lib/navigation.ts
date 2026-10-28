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
 * Depende dos perfis: o cliente pede serviços, o técnico recebe pedidos TRIM TRIM.
 */
export function navigationFor(roles: RoleName[] = []): NavItem[] {
    const items: NavItem[] = [{ label: 'Início', href: '/', icon: 'home', available: true }];

    if (roles.includes('client')) {
        items.push({ label: 'Pedir serviço', href: '/pedidos/novo', icon: 'search', available: true });
    }
    if (roles.includes('provider')) {
        items.push({ label: 'TRIM TRIM', href: '/tecnico/ofertas', icon: 'bell', available: true });
        items.push({ label: 'Carteira', href: '/carteira', icon: 'wallet', available: true });
    }
    if (roles.includes('company_rh')) {
        items.push({ label: 'Equipa', href: '/empresa/relatorios', icon: 'calendar', available: true });
    }
    if (roles.length === 0) {
        items.push({ label: 'Explorar', href: '/explorar', icon: 'search', available: false });
    }

    items.push(
        { label: 'Serviços', href: '/servicos', icon: 'calendar', available: roles.length > 0 },
        { label: 'Perfil', href: '/perfil', icon: 'user', available: true },
    );

    return items;
}
