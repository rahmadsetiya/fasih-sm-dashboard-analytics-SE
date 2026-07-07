<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <div
                class="relative min-h-svh overflow-hidden bg-[radial-gradient(circle_at_top_right,rgba(255,188,117,0.16),transparent_22%),radial-gradient(circle_at_top_left,rgba(251,191,36,0.10),transparent_18%)] dark:bg-[radial-gradient(circle_at_top_right,rgba(251,146,60,0.12),transparent_24%),radial-gradient(circle_at_top_left,rgba(245,158,11,0.08),transparent_18%)]"
            >
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-orange-300/60 to-transparent"
                />
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <div class="relative px-0 pb-8">
                    <slot />
                </div>
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
