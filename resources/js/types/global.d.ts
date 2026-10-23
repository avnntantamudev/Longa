import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';

export interface AuthUser {
    id: number;
    name: string;
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
