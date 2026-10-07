export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
    localStorage.removeItem('appearance');
    document.cookie = 'appearance=; path=/; max-age=0; SameSite=Lax';
}
