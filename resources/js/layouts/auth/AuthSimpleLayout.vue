<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChartScatter } from '@lucide/vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link href="/" class="flex flex-col items-center gap-2">
                        <div
                            class="mb-1 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500 text-white shadow-sm"
                        >
                            <ChartScatter class="size-6" />
                        </div>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p
                            v-if="description"
                            class="text-center text-sm text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
