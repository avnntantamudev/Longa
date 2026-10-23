import { ref } from 'vue';

/**
 * Pede a localização do dispositivo só quando o utilizador carrega no botão (nunca ao abrir a página).
 * Sem permissão, sem HTTPS ou sem sinal, a página deve oferecer a escolha manual da zona.
 */
export type GeoStatus = 'idle' | 'locating' | 'ok' | 'denied' | 'unavailable';

export function useGeolocation() {
    const status = ref<GeoStatus>('idle');
    const coords = ref<{ latitude: number; longitude: number; accuracy: number } | null>(null);

    function locate(): Promise<void> {
        if (!('geolocation' in navigator) || !window.isSecureContext) {
            status.value = 'unavailable';
            return Promise.resolve();
        }

        status.value = 'locating';

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    coords.value = {
                        latitude: Number(position.coords.latitude.toFixed(6)),
                        longitude: Number(position.coords.longitude.toFixed(6)),
                        accuracy: Math.round(position.coords.accuracy),
                    };
                    status.value = 'ok';
                    resolve();
                },
                (error) => {
                    status.value = error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable';
                    resolve();
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
            );
        });
    }

    return { status, coords, locate };
}
