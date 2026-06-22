<script setup lang="ts">
import type { AppVariant } from '@/types';

withDefaults(defineProps<{ variant?: AppVariant }>(), { variant: 'sidebar' });
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <div v-else class="flex min-h-svh bg-background">
        <slot />
    </div>
</template>
