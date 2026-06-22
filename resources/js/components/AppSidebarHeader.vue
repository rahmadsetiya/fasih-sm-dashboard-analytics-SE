<script setup lang="ts">
import { Menu } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useSidebar } from '@/composables/useSidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { toggleMobile } = useSidebar();
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 md:px-6"
    >
        <button
            class="flex items-center justify-center rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none md:hidden"
            aria-label="Buka menu"
            @click="toggleMobile"
        >
            <Menu class="size-5" />
        </button>

        <template v-if="breadcrumbs && breadcrumbs.length > 0">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </template>
    </header>
</template>
