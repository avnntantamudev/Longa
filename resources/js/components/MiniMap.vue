<script setup lang="ts">
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Mini mapa (Leaflet + OpenStreetMap, sem chave paga).
 * `approximate`: mostra só uma zona circular (privacidade antes de aceitar); senão mostra o ponto exato.
 * Os tiles OSM servem para desenvolvimento; em produção usar um fornecedor de tiles [AJUSTAR].
 */
const props = withDefaults(defineProps<{ lat: number; lng: number; approximate?: boolean; radiusM?: number; label?: string }>(), {
    approximate: true,
    radiusM: 800,
    label: '',
});

const el = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let layer: L.Layer | null = null;

function draw(): void {
    if (!map) {
        return;
    }
    layer?.remove();
    const center: L.LatLngExpression = [props.lat, props.lng];
    layer = props.approximate
        ? L.circle(center, { radius: props.radiusM, color: '#FF7A00', weight: 2, fillColor: '#FF7A00', fillOpacity: 0.15 })
        : L.circleMarker(center, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#FF7A00', fillOpacity: 1 });
    layer.addTo(map);
    if (props.label) {
        layer.bindTooltip(props.label, { permanent: true, direction: 'top', className: 'longa-map-label' });
    }
    map.setView(center, props.approximate ? 14 : 15);
}

onMounted(() => {
    if (!el.value) {
        return;
    }
    map = L.map(el.value, { zoomControl: false, attributionControl: true, dragging: true, scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '© OpenStreetMap',
    }).addTo(map);
    draw();
});

watch(() => [props.lat, props.lng, props.approximate], draw);

onBeforeUnmount(() => map?.remove());
</script>

<template>
    <div ref="el" class="isolate z-0 h-36 w-full overflow-hidden rounded-control bg-navy-100" role="img" :aria-label="label || 'Mapa'" />
</template>

<style>
.longa-map-label {
    font-family: inherit;
    font-weight: 600;
    color: #0b1f3a;
}
</style>
