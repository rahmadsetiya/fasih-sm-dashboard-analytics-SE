<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, ClipboardList, Users, X } from '@lucide/vue';
import Drawer from 'primevue/drawer';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import ChangelogDialog from '@/components/ChangelogDialog.vue';
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
    appVersion: string;
    auth: { user: { is_admin: boolean } };
}>();
const isAdmin = computed(() => page.props.auth?.user?.is_admin ?? false);

const mainNavItems: NavItem[] = [
    { title: 'Ringkasan Kabupaten', href: '/ringkasan', icon: ClipboardList },
    { title: 'Dashboard FASIH', href: '/', icon: BarChart3 },
];

const adminNavItems: NavItem[] = [
    { title: 'Manajemen User', href: '/admin/users', icon: Users },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <aside
        class="sticky top-0 hidden h-svh flex-col overflow-y-auto border-r border-sidebar-border/80 bg-[linear-gradient(180deg,rgba(255,255,255,0.98)_0%,rgba(255,249,241,0.98)_100%)] md:flex md:w-72 md:shrink-0 dark:bg-[linear-gradient(180deg,rgba(23,21,19,0.98)_0%,rgba(15,12,10,0.98)_100%)]"
    >
        <div class="border-b border-sidebar-border/80 px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <Link
                    :href="dashboard()"
                    class="flex min-w-0 items-center gap-3"
                >
                    <AppLogo />
                </Link>
                <span
                    class="shrink-0 rounded-full bg-orange-100 px-2 py-1 text-[10px] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-200"
                >
                    v{{ page.props.appVersion }}
                </span>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-2 py-4">
            <div
                class="px-3 pb-2 text-[11px] font-semibold tracking-[0.22em] text-sidebar-foreground/45 uppercase"
            >
                Workspace
            </div>
            <NavMain :items="mainNavItems" />
            <div
                v-if="isAdmin"
                class="px-3 pt-4 pb-2 text-[11px] font-semibold tracking-[0.22em] text-sidebar-foreground/45 uppercase"
            >
                Admin
            </div>
            <NavMain v-if="isAdmin" :items="adminNavItems" />
            <NavFooter :items="footerNavItems" class="mt-3" />
        </div>

        <div class="shrink-0 space-y-2 border-t border-sidebar-border/80 p-3">
            <ChangelogDialog
                class="inline-flex rounded-md px-3 py-1.5 text-xs font-semibold text-orange-700 transition-colors hover:bg-orange-50 hover:text-orange-800 dark:text-orange-200 dark:hover:bg-orange-500/10"
                compact
            />
            <ImportDbButton />
            <RegionNamesButton />
            <NavUser />
            <p
                class="px-3 pt-1 text-center text-[10px] leading-tight text-muted-foreground/45"
            >
                Dashboard internal IPDS BPS Enrekang
            </p>
        </div>
    </aside>

    <Drawer
        v-model:visible="mobileOpen"
        position="left"
        :style="{ width: '18rem', padding: '0' }"
        :pt="{
            header: { style: 'display: none' },
            content: {
                class: 'p-0 flex flex-col bg-sidebar h-full bg-[linear-gradient(180deg,rgba(255,255,255,0.98)_0%,rgba(255,249,241,0.98)_100%)] dark:bg-[linear-gradient(180deg,rgba(23,21,19,0.98)_0%,rgba(15,12,10,0.98)_100%)]',
            },
        }"
    >
        <div class="border-b border-sidebar-border/80 px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <Link
                    :href="dashboard()"
                    class="flex items-center gap-3"
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
        </div>

        <div class="flex-1 overflow-y-auto px-2 py-4">
            <div
                class="px-3 pb-2 text-[11px] font-semibold tracking-[0.22em] text-sidebar-foreground/45 uppercase"
            >
                Workspace
            </div>
            <NavMain :items="mainNavItems" />
            <div
                v-if="isAdmin"
                class="px-3 pt-4 pb-2 text-[11px] font-semibold tracking-[0.22em] text-sidebar-foreground/45 uppercase"
            >
                Admin
            </div>
            <NavMain v-if="isAdmin" :items="adminNavItems" />
            <NavFooter :items="footerNavItems" class="mt-3" />
        </div>

        <div class="shrink-0 space-y-2 border-t border-sidebar-border/80 p-3">
            <ChangelogDialog
                class="inline-flex rounded-md px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50 dark:text-orange-200 dark:hover:bg-orange-500/10"
                compact
            />
            <ImportDbButton />
            <RegionNamesButton />
            <NavUser />
        </div>
    </Drawer>
</template>
