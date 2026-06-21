<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, BarChart3, ClipboardList, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import ImportDbButton from '@/components/ImportDbButton.vue';
import NavFooter from '@/components/NavFooter.vue';
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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage<{ auth: { user: { is_admin: boolean } }; latest_snapshot: string | null }>();
const isAdmin = computed(() => page.props.auth?.user?.is_admin ?? false);

const latestSnapshotLabel = computed(() => {
    const raw = page.props.latest_snapshot;
    if (!raw) return null;
    const d = new Date(raw);
    return d.toLocaleString('id-ID', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
});

const mainNavItems: NavItem[] = [
    {
        title: 'Ringkasan Kabupaten',
        href: '/ringkasan',
        icon: ClipboardList,
    },
    {
        title: 'Dashboard FASIH',
        href: '/',
        icon: BarChart3,
    },
    {
        title: 'Heatmap Aktivitas',
        href: '/heatmap',
        icon: Activity,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Manajemen User',
        href: '/admin/users',
        icon: Users,
    },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="isAdmin" :items="adminNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <div
                v-if="latestSnapshotLabel"
                class="px-3 py-1.5 text-[10px] text-muted-foreground/60"
            >
                Data per: {{ latestSnapshotLabel }}
            </div>
            <SidebarMenu>
                <ImportDbButton />
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
