import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { xsrfToken } from '@/lib/http';

let instance: Echo<'reverb'> | null = null;

/**
 * Realtime is only an accelerator: every device also polls, so a failed websocket never
 * blocks the TPV.
 */
export function echo(): Echo<'reverb'> | null {
    if (instance) {
        return instance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    const envHost = (import.meta.env.VITE_REVERB_HOST as string | undefined) || window.location.hostname;
    const host = envHost === 'localhost' ? window.location.hostname : envHost;
    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? window.location.protocol.replace(':', '');
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    try {
        instance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel: { name: string }) => ({
                authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
                    fetch('/broadcasting/auth', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                    })
                        .then(async (response) => {
                            if (!response.ok) {
                                throw new Error(`auth ${response.status}`);
                            }

                            callback(null, (await response.json()) as { auth: string });
                        })
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });
    } catch {
        instance = null;
    }

    return instance;
}
