import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { t, tr } from '@/i18n';
import { state } from './store';

export const soundEnabled = ref(localStorage.getItem('tpv-sound') !== 'off');

watch(soundEnabled, (value) => localStorage.setItem('tpv-sound', value ? 'on' : 'off'));

let audio: AudioContext | null = null;

export function beep(kind: 'new' | 'ready' = 'new'): void {
    if (!soundEnabled.value) {
        return;
    }

    try {
        audio ??= new AudioContext();
        const notes = kind === 'new' ? [880, 1175] : [660, 880, 1320];

        notes.forEach((frequency, index) => {
            const osc = audio!.createOscillator();
            const gain = audio!.createGain();
            osc.frequency.value = frequency;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.25, audio!.currentTime + index * 0.15);
            gain.gain.exponentialRampToValueAtTime(0.001, audio!.currentTime + index * 0.15 + 0.14);
            osc.connect(gain).connect(audio!.destination);
            osc.start(audio!.currentTime + index * 0.15);
            osc.stop(audio!.currentTime + index * 0.15 + 0.15);
        });
    } catch {
        // Audio is optional.
    }
}

let known: Map<string, string> | null = null;

/**
 * Waiters get a toast when a kitchen ticket turns ready; kitchen screens beep on new tickets.
 */
watch(
    () => Object.values(state.kitchenTickets).map((k) => `${k.uuid}:${k.status}:${k.held ? 1 : 0}`).join('|'),
    () => {
        const tickets = Object.values(state.kitchenTickets);
        const current = new Map(tickets.map((k) => [k.uuid, k.status + (k.held ? 'h' : '')]));

        if (known === null) {
            known = current;

            return;
        }

        const isKitchen = state.me?.role === 'kitchen' || state.device?.type === 'kds';

        for (const ticket of tickets) {
            const before = known.get(ticket.uuid);

            if (isKitchen && before === undefined && !ticket.held) {
                beep('new');
            }

            if (isKitchen && before === 'pendingh' && !ticket.held) {
                beep('new');
            }

            if (!isKitchen && ticket.status === 'ready' && before !== undefined && !before.startsWith('ready')) {
                const destination = state.destinations[ticket.destinationId];
                toast.success(t('kds.readyNotice', { table: ticket.tableLabel ?? '', destination: tr(destination?.name) }), { duration: 8000 });
                beep('ready');
            }
        }

        known = current;
    },
);
