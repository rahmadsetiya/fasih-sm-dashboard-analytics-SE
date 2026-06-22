<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { BreadcrumbItem } from '@/types';

defineProps<{ breadcrumbs: BreadcrumbItem[] }>();
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5 text-sm text-muted-foreground">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <li>
                    <template v-if="index === breadcrumbs.length - 1">
                        <span class="font-medium text-foreground">{{
                            item.title
                        }}</span>
                    </template>
                    <template v-else>
                        <Link
                            :href="item.href"
                            class="transition-colors hover:text-foreground"
                        >
                            {{ item.title }}
                        </Link>
                    </template>
                </li>
                <li
                    v-if="index !== breadcrumbs.length - 1"
                    aria-hidden="true"
                    class="select-none"
                >
                    /
                </li>
            </template>
        </ol>
    </nav>
</template>
