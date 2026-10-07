import { computed, ref } from 'vue';

const hash = ref(typeof window !== 'undefined' ? window.location.hash : '');

if (typeof window !== 'undefined') {
    window.addEventListener('hashchange', () => {
        hash.value = window.location.hash;
    });
}

export const route = computed(() => {
    const path = hash.value.replace(/^#/, '') || '/';
    const [, name = '', ...params] = path.split('/');

    return { path, name, params };
});

export function go(path: string): void {
    window.location.hash = path.startsWith('#') ? path : `#${path}`;
}

export function back(fallback = '/sala'): void {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        go(fallback);
    }
}
