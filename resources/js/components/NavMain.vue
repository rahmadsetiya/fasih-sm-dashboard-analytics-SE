<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{ items: NavItem[] }>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <nav class="px-2 py-1">
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            :class="[
                'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                isCurrentUrl(item.href)
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
            ]"
        >
            <component :is="item.icon" class="size-4 shrink-0" />
            <span>{{ item.title }}</span>
        </Link>
    </nav>
</template>
