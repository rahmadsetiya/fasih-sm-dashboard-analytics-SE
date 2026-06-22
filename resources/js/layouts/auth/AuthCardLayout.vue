<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChartScatter } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link href="/" class="flex items-center gap-2 self-center">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500 text-white shadow-sm"
                >
                    <ChartScatter class="size-6" />
                </div>
            </Link>

            <div class="flex flex-col gap-6">
                <Card class="rounded-xl">
                    <CardHeader class="px-10 pt-8 pb-0 text-center">
                        <CardTitle class="text-xl">{{ title }}</CardTitle>
                    </CardHeader>
                    <CardContent class="px-10 py-8">
                        <slot />
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
