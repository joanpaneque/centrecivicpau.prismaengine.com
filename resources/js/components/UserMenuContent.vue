<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Languages, LayoutPanelTop, LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useI18n } from '@/i18n';
import { logout, tpv } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const { t, locale, setLocale } = useI18n();

const handleLogout = () => {
    router.flushAll();
};

async function toggleLocale(): Promise<void> {
    await setLocale(locale.value === 'ca' ? 'es' : 'ca');
    router.reload();
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <a class="block w-full cursor-pointer" :href="tpv().url">
                <LayoutPanelTop class="mr-2 h-4 w-4" />
                {{ t('nav.backToTpv') }}
            </a>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                {{ t('nav.profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem @click="toggleLocale">
            <Languages class="mr-2 h-4 w-4" />
            {{ locale === 'ca' ? t('common.spanish') : t('common.catalan') }}
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
