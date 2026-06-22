<script setup lang="ts">
import { computed } from 'vue';
import type { AppVariant } from '@/types';

const props = withDefaults(defineProps<{ variant?: AppVariant; class?: string }>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <div
        v-if="props.variant === 'sidebar'"
        :class="['flex min-h-svh flex-1 flex-col min-w-0', className]"
    >
        <slot />
    </div>
    <main
        v-else
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
