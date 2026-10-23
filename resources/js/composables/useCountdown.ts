import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Contagem decrescente apenas para MOSTRAR o tempo. O prazo é decidido pelo servidor:
 * usamos `seconds_left` (calculado no servidor no momento da resposta), o que evita erros
 * quando o relógio do telemóvel está errado. Aceitar depois do prazo é recusado pelo servidor.
 */
export function useCountdown() {
    const deadline = ref<number | null>(null);
    const now = ref(Date.now());
    const timer = setInterval(() => (now.value = Date.now()), 250);
    onBeforeUnmount(() => clearInterval(timer));

    const secondsLeft = computed(() => (deadline.value === null ? 0 : Math.max(0, Math.ceil((deadline.value - now.value) / 1000))));

    function start(serverSecondsLeft: number): void {
        deadline.value = Date.now() + serverSecondsLeft * 1000;
    }

    return { secondsLeft, start, finished: computed(() => deadline.value !== null && secondsLeft.value === 0) };
}
