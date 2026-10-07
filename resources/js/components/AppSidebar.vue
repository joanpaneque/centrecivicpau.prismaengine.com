<script setup lang="ts">
import {
    BookOpen,
    CalendarClock,
    Clock,
    KeyRound,
    LayoutGrid,
    LayoutPanelTop,
    MapPinned,
    MonitorSmartphone,
    Printer,
    Receipt,
    ReceiptText,
    ScrollText,
    Settings,
    ShieldCheck,
    Sparkles,
    Truck,
    Users,
    Utensils,
    Vault,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useI18n } from '@/i18n';
import { dashboard, tpv } from '@/routes';
import {
    apiTokens,
    audit,
    cashSessions,
    catalog,
    devices,
    invoices,
    printing,
    setMenus,
    settings,
    shifts,
    suppliers,
    tickets,
    time,
    users,
    zones,
} from '@/routes/admin';
import { log as printLog } from '@/routes/admin/printing';
import type { NavItem } from '@/types';

const { state } = useSidebar();
const { t } = useI18n();

const groups = computed<{ label?: string; items: NavItem[] }[]>(() => [
    {
        items: [
            { title: t('nav.panel'), href: dashboard(), icon: LayoutGrid },
            { title: t('nav.assistant'), href: '/gestion/asistente', icon: Sparkles },
        ],
    },
    {
        label: t('nav.floor'),
        items: [
            { title: t('nav.zones'), href: zones(), icon: MapPinned },
            { title: t('nav.devices'), href: devices(), icon: MonitorSmartphone },
        ],
    },
    {
        label: t('nav.catalog'),
        items: [
            { title: t('nav.catalog'), href: catalog(), icon: BookOpen },
            { title: t('nav.setMenus'), href: setMenus(), icon: Utensils },
            { title: t('nav.printing'), href: printing(), icon: Printer },
            { title: t('nav.printLog'), href: printLog(), icon: ScrollText },
        ],
    },
    {
        label: t('nav.cashier'),
        items: [
            { title: t('nav.tickets'), href: tickets(), icon: Receipt },
            { title: t('nav.invoices'), href: invoices(), icon: ReceiptText },
            { title: t('nav.cashSessions'), href: cashSessions(), icon: Vault },
        ],
    },
    {
        label: t('nav.users'),
        items: [
            { title: t('nav.users'), href: users(), icon: Users },
            { title: t('nav.timeTracking'), href: time(), icon: Clock },
            { title: t('nav.shiftPlanner'), href: shifts(), icon: CalendarClock },
        ],
    },
    {
        label: t('nav.management'),
        items: [
            { title: t('nav.suppliers'), href: suppliers(), icon: Truck },
            { title: t('nav.settings'), href: settings(), icon: Settings },
            { title: t('nav.audit'), href: audit(), icon: ShieldCheck },
            { title: t('nav.apiTokens'), href: apiTokens(), icon: KeyRound },
        ],
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader
            class="flex items-center px-2 pt-2 pb-4 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:pb-5"
        >
            <AppLogo :icon-only="state === 'collapsed'" />
        </SidebarHeader>

        <SidebarContent class="gap-3">
            <SidebarMenu class="px-2">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        :tooltip="t('nav.tpv')"
                        class="bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground"
                    >
                        <a :href="tpv().url">
                            <LayoutPanelTop />
                            <span>{{ t('nav.backToTpv') }}</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavMain
                v-for="(group, index) in groups"
                :key="index"
                :label="group.label"
                :items="group.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
