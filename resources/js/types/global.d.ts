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
    area: string;
}

export interface Zone {
    province: string;
    municipality: string;
    neighborhood: string;
    lat: number;
    lng: number;
}
