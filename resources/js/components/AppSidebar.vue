<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, BarChart3, ClipboardList, Table2, UserCheck, Users, X } from '@lucide/vue';
import Drawer from 'primevue/drawer';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import ImportDbButton from '@/components/ImportDbButton.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import RegionNamesButton from '@/components/RegionNamesButton.vue';
import { useSidebar } from '@/composables/useSidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const { mobileOpen } = useSidebar();
const page = usePage<{
    auth: { user: { is_admin: boolean } };
    latest_snapshot: string | null;
}>();
const isAdmin = computed(() => page.props.auth?.user?.is_admin ?? false);

const latestSnapshotLabel = computed(() => {
    const raw = page.props.latest_snapshot;

    if (!raw) {
return null;
}

    const d = new Date(raw);

    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const mainNavItems: NavItem[] = [
    { title: 'Ringkasan Kabupaten', href: '/ringkasan', icon: ClipboardList },
    { title: 'Dashboard FASIH', href: '/', icon: BarChart3 },
    { title: 'Heatmap Aktivitas', href: '/heatmap', icon: Activity },
    { title: 'Analitik Petugas', href: '/petugas', icon: UserCheck },
    { title: 'Daftar Penugasan', href: '/penugasan', icon: Table2 },
];

const adminNavItems: NavItem[] = [
    { title: 'Manajemen User', href: '/admin/users', icon: Users },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <!-- Desktop sidebar -->
    <aside
        class="hidden md:flex md:w-64 md:shrink-0 flex-col h-svh sticky top-0 border-r border-sidebar-border bg-sidebar overflow-y-auto"
    >
        <div
            class="flex h-14 shrink-0 items-center border-b border-sidebar-border px-3"
        >
            <Link :href="dashboard()" class="flex items-center gap-2">
                <AppLogo />
            </Link>
        </div>

        <div class="flex-1 overflow-y-auto py-2">
            <NavMain :items="mainNavItems" />
            <NavMain v-if="isAdmin" :items="adminNavItems" />
            <NavFooter :items="footerNavItems" />
        </div>

        <div class="shrink-0 border-t border-sidebar-border p-2 space-y-0.5">
            <div
                v-if="latestSnapshotLabel"
                class="px-3 py-1 text-[10px] text-muted-foreground/60"
            >
                Data per: {{ latestSnapshotLabel }}
            </div>
            <ImportDbButton />
            <RegionNamesButton />
            <NavUser />
        </div>
    </aside>

    <!-- Mobile Drawer -->
    <Drawer
        v-model:visible="mobileOpen"
        position="left"
        :style="{ width: '16rem', padding: '0' }"
        :pt="{
            header: { style: 'display: none' },
            content: { class: 'p-0 flex flex-col bg-sidebar h-full' },
        }"
    >
        <div
            class="flex h-14 shrink-0 items-center justify-between border-b border-sidebar-border px-3"
        >
            <Link
                :href="dashboard()"
                class="flex items-center gap-2"
                @click="mobileOpen = false"
            >
                <AppLogo />
            </Link>
            <button
                class="rounded-md p-1 text-muted-foreground hover:bg-sidebar-accent/60 focus:outline-none"
                @click="mobileOpen = false"
                aria-label="Tutup menu"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto py-2">
            <NavMain :items="mainNavItems" />
            <NavMain v-if="isAdmin" :items="adminNavItems" />
            <NavFooter :items="footerNavItems" />
        </div>

        <div class="shrink-0 border-t border-sidebar-border p-2 space-y-0.5">
            <div
                v-if="latestSnapshotLabel"
                class="px-3 py-1 text-[10px] text-muted-foreground/60"
            >
                Data per: {{ latestSnapshotLabel }}
            </div>
            <ImportDbButton />
            <RegionNamesButton />
            <NavUser />
        </div>
    </Drawer>
</template>
