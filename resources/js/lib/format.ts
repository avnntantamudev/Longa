/**
 * Formatos em português de Angola. Montantes sempre em Kz inteiros.
 */
const kz = new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 0 });

/** 25000 → "25.000 Kz" */
export function kwanza(amount: number): string {
    return `${kz.format(amount).replace(/\s/g, '.')} Kz`;
}

/** 1234 → "1,2 km"; 800 → "800 m" */
export function distance(meters: number): string {
    if (meters < 1000) {
        return `${Math.round(meters / 100) * 100} m`;
    }
    return `${(meters / 1000).toFixed(1).replace('.', ',')} km`;
}

/** "hoje 14:00", "amanhã 09:30", "sáb, 25/10 14:00" */
export function when(iso: string): string {
    const date = new Date(iso);
    const time = date.toLocaleTimeString('pt-AO', { hour: '2-digit', minute: '2-digit' });
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);

    if (date.toDateString() === today.toDateString()) {
        return `hoje ${time}`;
    }
    if (date.toDateString() === tomorrow.toDateString()) {
        return `amanhã ${time}`;
    }
    return `${date.toLocaleDateString('pt-AO', { weekday: 'short', day: '2-digit', month: '2-digit' })} ${time}`;
}

/** Valor para <input type="datetime-local"> (hora local do dispositivo) */
export function toLocalInput(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
