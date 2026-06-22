<script setup lang="ts">
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

defineProps<{ items: NavItem[]; class?: string }>();
</script>

<template>
    <nav v-if="items.length" :class="['px-2 py-1', $props.class]">
        <a
            v-for="item in items"
            :key="item.title"
            :href="toUrl(item.href)"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-neutral-600 transition-colors hover:bg-sidebar-accent/60 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
        >
            <component :is="item.icon" class="size-4 shrink-0" />
            <span>{{ item.title }}</span>
        </a>
    </nav>
</template>
