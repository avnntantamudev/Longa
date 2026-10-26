<script setup lang="ts">
import 'leaflet/dist/leaflet.css';
import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import { useGeolocation } from '@/composables/useGeolocation';
import type { Zone } from '@/types/global';
import L from 'leaflet';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Escolher o local: no mapa (tocar ou arrastar o marcador) ou com "Usar a minha localização".
 * Município e bairro são texto livre com sugestões das zonas conhecidas; o que conta para encontrar
 * pessoas perto é o ponto no mapa. Escolher um bairro sugerido centra o mapa nele (se ainda não houver ponto).
 */
export interface LocationValue {
    municipality: string;
    neighborhood: string;
    latitude: number | null;
    longitude: number | null;
}

// O objeto (reactive) do formulário da página: o componente altera os campos diretamente
const props = withDefaults(defineProps<{ value: LocationValue; zones: Zone[]; radiusM?: number | null; errors?: Record<string, string> }>(), { radiusM: null, errors: () => ({}) });
const model = computed(() => props.value);

const LUANDA: [number, number] = [-8.8383, 13.2344];
const el = ref<HTMLElement | null>(null);
const geo = useGeolocation();
let map: L.Map | null = null;
let marker: L.Marker | null = null;
let circle: L.Circle | null = null;

const municipalities = computed(() => [...new Set(props.zones.map((z) => z.municipality))].sort());
const neighborhoods = computed(() =>
    props.zones.filter((z) => !model.value.municipality || z.municipality === model.value.municipality).map((z) => z.neighborhood),
);

const pin = L.divIcon({
    className: '',
    html: '<span style="display:block;width:28px;height:28px;border-radius:50% 50% 50% 0;background:#FF7A00;border:3px solid #fff;transform:rotate(-45deg);box-shadow:0 2px 6px rgba(0,0,0,.35)"></span>',
    iconSize: [28, 28],
    iconAnchor: [14, 28],
});

function setPoint(lat: number, lng: number, recenter = false): void {
    model.value.latitude = Number(lat.toFixed(6));
    model.value.longitude = Number(lng.toFixed(6));
    if (recenter) map?.setView([lat, lng], 15);
}

function draw(): void {
    if (!map) return;
    const { latitude, longitude } = model.value;
    if (latitude === null || longitude === null) {
        marker?.remove();
        circle?.remove();
        marker = circle = null;
        return;
    }
    if (!marker) {
        marker = L.marker([latitude, longitude], { icon: pin, draggable: true, keyboard: true, title: 'Local (arraste para ajustar)' }).addTo(map);
        marker.on('dragend', () => {
            const p = marker!.getLatLng();
            setPoint(p.lat, p.lng);
        });
    } else {
        marker.setLatLng([latitude, longitude]);
    }
    if (props.radiusM) {
        if (!circle) circle = L.circle([latitude, longitude], { radius: props.radiusM, color: '#FF7A00', weight: 1, fillOpacity: 0.08 }).addTo(map);
        circle.setLatLng([latitude, longitude]).setRadius(props.radiusM);
    }
}

async function useMyLocation(): Promise<void> {
    await geo.locate();
    if (!geo.coords.value) return;
    const { latitude, longitude } = geo.coords.value;
    setPoint(latitude, longitude, true);
    // Bairro mais próximo das zonas conhecidas, se ainda não estiver escrito (pode ser corrigido)
    if (!model.value.neighborhood) {
        const nearest = [...props.zones].sort((a, b) => Math.hypot(a.lat - latitude, a.lng - longitude) - Math.hypot(b.lat - latitude, b.lng - longitude))[0];
        if (nearest) {
            model.value.neighborhood = nearest.neighborhood;
            model.value.municipality ||= nearest.municipality;
        }
    }
}

function onNeighborhood(): void {
    const zone = props.zones.find((z) => z.neighborhood.toLowerCase() === model.value.neighborhood.trim().toLowerCase());
    if (!zone) return;
    if (!model.value.municipality) model.value.municipality = zone.municipality;
    if (model.value.latitude === null) setPoint(zone.lat, zone.lng, true);
    else map?.setView([zone.lat, zone.lng], 15);
}

onMounted(() => {
    if (!el.value) return;
    const start: [number, number] = model.value.latitude !== null && model.value.longitude !== null ? [model.value.latitude, model.value.longitude] : LUANDA;
    map = L.map(el.value, { zoomControl: true, scrollWheelZoom: false }).setView(start, model.value.latitude !== null ? 15 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    map.on('click', (e: L.LeafletMouseEvent) => setPoint(e.latlng.lat, e.latlng.lng));
    draw();
});

watch(() => [model.value.latitude, model.value.longitude, props.radiusM], draw);
onBeforeUnmount(() => map?.remove());
</script>

<template>
    <div class="grid gap-3">
        <button type="button" class="btn-secondary w-full" :disabled="geo.status.value === 'locating'" @click="useMyLocation">
            <AppIcon name="map-pin" :size="20" />
            {{ geo.status.value === 'locating' ? 'A obter localização…' : 'Usar a minha localização' }}
        </button>
        <p v-if="geo.status.value === 'denied' || geo.status.value === 'unavailable'" class="text-caption text-warning">
            Não foi possível usar a localização. Toque no mapa para marcar o local.
        </p>

        <div>
            <div ref="el" class="isolate z-0 h-64 w-full overflow-hidden rounded-control bg-navy-100" aria-label="Mapa: toque para marcar o local" />
            <p class="mt-1 text-caption" :class="model.latitude === null ? 'text-ink-muted' : 'text-success'">
                {{ model.latitude === null ? 'Toque no mapa para marcar o local.' : 'Local marcado. Arraste o marcador para ajustar.' }}
            </p>
            <p v-if="errors.latitude || errors.longitude" class="text-caption text-danger">{{ errors.latitude || errors.longitude }}</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <FormField label="Município" for="loc-municipality" :error="errors.municipality">
                <input id="loc-municipality" v-model="model.municipality" class="input" list="loc-municipalities" maxlength="80" placeholder="Ex.: Talatona" autocomplete="off" />
                <datalist id="loc-municipalities">
                    <option v-for="m in municipalities" :key="m" :value="m" />
                </datalist>
            </FormField>
            <FormField label="Bairro" for="loc-neighborhood" :error="errors.neighborhood">
                <input id="loc-neighborhood" v-model="model.neighborhood" class="input" list="loc-neighborhoods" maxlength="80" placeholder="Escreva o bairro" autocomplete="off" @change="onNeighborhood" />
                <datalist id="loc-neighborhoods">
                    <option v-for="n in neighborhoods" :key="n" :value="n" />
                </datalist>
            </FormField>
        </div>
    </div>
</template>
