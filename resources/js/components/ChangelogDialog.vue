<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CalendarDays, Check, FileText, Tag } from '@lucide/vue';
import { computed } from 'vue';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { ReleaseEntry } from '@/types';

withDefaults(defineProps<{ class?: string; compact?: boolean }>(), {
    class: '',
    compact: false,
});

const page = usePage<{ appVersion: string; release_history: ReleaseEntry[] }>();
const releases = computed(() => page.props.release_history ?? []);

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });
}
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <button
                type="button"
                :class="[
                    compact
                        ? ''
                        : 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                    $props.class,
                ]"
            >
                <FileText v-if="!compact" class="size-4 shrink-0" />
                <span>{{
                    compact ? 'Lihat changelog lengkap' : 'Changelog'
                }}</span>
            </button>
        </DialogTrigger>

        <DialogScrollContent class="max-h-[85vh] max-w-2xl overflow-hidden p-0">
            <div
                class="border-b bg-gradient-to-br from-orange-50 via-background to-amber-50 px-6 py-6 dark:from-orange-950/30 dark:to-background"
            >
                <DialogHeader>
                    <div
                        class="mb-1 flex items-center gap-2 text-sm font-semibold text-orange-700 dark:text-orange-300"
                    >
                        <Tag class="size-4" />
                        Versi aktif v{{ page.props.appVersion }}
                    </div>
                    <DialogTitle class="text-2xl"
                        >Riwayat Perubahan</DialogTitle
                    >
                    <DialogDescription>
                        Daftar pembaruan fitur, perbaikan, dan perubahan penting
                        aplikasi.
                    </DialogDescription>
                </DialogHeader>
            </div>

            <div class="space-y-7 px-6 py-6">
                <article
                    v-for="(release, index) in releases"
                    :key="release.version"
                    class="relative pl-7"
                >
                    <span
                        class="absolute top-1 left-0 size-3 rounded-full bg-orange-500 ring-4 ring-orange-100 dark:ring-orange-950"
                    />
                    <span
                        v-if="index < releases.length - 1"
                        class="absolute top-5 bottom-[-2rem] left-[5px] w-px bg-border"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold">
                            {{ release.title }}
                        </h3>
                        <span
                            class="rounded-full bg-orange-100 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-200"
                            >v{{ release.version }}</span
                        >
                    </div>
                    <div
                        class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <CalendarDays class="size-3.5" />
                        {{ formatDate(release.released_at) }}
                    </div>
                    <p class="mt-3 text-sm leading-6 text-muted-foreground">
                        {{ release.summary }}
                    </p>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="highlight in release.highlights"
                            :key="highlight"
                            class="flex gap-2 text-sm leading-6"
                        >
                            <Check
                                class="mt-1 size-4 shrink-0 text-emerald-600"
                            />
                            <span>{{ highlight }}</span>
                        </li>
                    </ul>
                </article>
                <p
                    v-if="releases.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    Belum ada catatan perubahan.
                </p>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
