import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';

export type RoleName = 'client' | 'provider' | 'company_rh' | 'admin' | 'operator';

export interface AuthUser {
    id: number;
    name: string | null;
    phone: string;
    photo_url: string | null;
    photo_thumb_url: string | null;
    roles: RoleName[];
    needs_onboarding: boolean;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    flash: { success?: string | null; error?: string | null };
}

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = InertiaPageProps & SharedProps & T;

export interface Category {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    /** Caminho completo na árvore, ex.: "Casa › Climatização › Manutenção de ar condicionado" */
    path: string;
    /** Caminho do pai, para agrupar listas */
    group: string | null;
    pricing_mode: 'fixed' | 'quote' | 'hourly_package';
    modalities: Array<'in_person' | 'online'>;
}

export interface Zone {
    province: string;
    municipality: string;
    neighborhood: string;
    lat: number;
    lng: number;
}
