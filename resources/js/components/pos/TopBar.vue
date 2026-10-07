<script setup lang="ts">
import { CalendarClock, ChefHat, Clock, Cog, Languages, LayoutGrid, LogOut, Menu, Receipt, RefreshCw, Sparkles, Volume2, VolumeX } from '@lucide/vue';
import { computed, ref } from 'vue';
import OperatorSwitch from '@/components/pos/OperatorSwitch.vue';
import SyncIndicator from '@/components/pos/SyncIndicator.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { locale, setLocale, t } from '@/i18n';
import { xsrfToken } from '@/lib/http';
import { soundEnabled } from '@/pos/notifications';
import { go, route } from '@/pos/router';
import { operator, state } from '@/pos/store';
import { resetLocalData } from '@/pos/sync';

const switching = ref(false);

const role = computed(() => (operator() as { role?: string } | null)?.role ?? state.me?.role ?? 'staff');
const isAdmin = computed(() => state.me?.role === 'admin' || role.value === 'admin');

const tabs = computed(() => {
    const items = [] as { name: string; label: string; icon: typeof LayoutGrid }[];
    const kitchen = role.value === 'kitchen';

    if (!kitchen) {
        items.push({ name: 'sala', label: t('nav.floor'), icon: LayoutGrid });
    }

    if (state.device?.type === 'cashier') {
        items.push({ name: 'caixa', label: t('nav.cashier'), icon: Receipt });
    }

    items.push({ name: 'cuina', label: t('nav.kitchen'), icon: ChefHat });

    if (!kitchen) {
        items.push({ name: 'reserves', label: t('nav.reservations'), icon: CalendarClock });
    }

    items.push({ name: 'fitxar', label: t('nav.clock'), icon: Clock });
    items.push({ name: 'assistent', label: t('nav.assistant'), icon: Sparkles });

    return items;
});

const active = computed(() => (route.value.name === 'comanda' || route.value.name === 'taula' ? 'sala' : route.value.name));
const current = computed(() => operator());

async function logout(): Promise<void> {
    try {
        await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() } });
    } finally {
        window.location.href = '/login';
    }
}

function toggleLocale(): void {
    void setLocale(locale.value === 'ca' ? 'es' : 'ca');
}
</script>

<template>
    <header class="flex h-14 shrink-0 items-center gap-2 bg-[#00056a] px-2 text-white shadow-md sm:px-3">
        <img src="/images/logo-centre-civic-icon.png" alt="" class="hidden size-9 rounded-md bg-white p-0.5 sm:block" />

        <nav class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
            <button
                v-for="tab in tabs"
                :key="tab.name"
                type="button"
                class="flex h-10 shrink-0 items-center gap-2 rounded-lg px-3 text-sm font-medium transition"
                :class="active === tab.name ? 'bg-white text-[#00056a]' : 'text-white/85 hover:bg-white/10'"
                @click="go(`/${tab.name}`)"
            >
                <component :is="tab.icon" class="size-5" />
                <span class="hidden md:inline">{{ tab.label }}</span>
            </button>
        </nav>

        <SyncIndicator />

        <button
            type="button"
            class="flex h-10 items-center gap-2 rounded-lg bg-white/10 px-2 text-sm font-medium hover:bg-white/20 sm:px-3"
            :title="t('operator.switch')"
            @click="switching = true"
        >
            <span class="flex size-7 items-center justify-center rounded-full text-xs font-bold" :style="{ background: current?.color || '#ffffff', color: current?.color ? '#fff' : '#00056a' }">
                {{ current?.name?.slice(0, 2).toUpperCase() }}
            </span>
            <span class="hidden max-w-28 truncate lg:inline">{{ current?.name }}</span>
        </button>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button type="button" class="flex size-10 items-center justify-center rounded-lg hover:bg-white/10">
                    <Menu class="size-5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
                <DropdownMenuItem @select="toggleLocale">
                    <Languages class="size-4" />
                    {{ locale === 'ca' ? 'Castellano' : 'Català' }}
                </DropdownMenuItem>
                <DropdownMenuItem @select="soundEnabled = !soundEnabled">
                    <component :is="soundEnabled ? Volume2 : VolumeX" class="size-4" />
                    {{ t('kds.sound') }}: {{ soundEnabled ? t('common.yes') : t('common.no') }}
                </DropdownMenuItem>
                <DropdownMenuItem v-if="isAdmin" as-child>
                    <a href="/panel"><Cog class="size-4" /> {{ t('nav.management') }}</a>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="resetLocalData">
                    <RefreshCw class="size-4" />
                    {{ t('sync.reload') }}
                </DropdownMenuItem>
                <DropdownMenuItem @select="logout">
                    <LogOut class="size-4" />
                    {{ t('nav.logout') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <OperatorSwitch v-model:open="switching" />
    </header>
</template>
