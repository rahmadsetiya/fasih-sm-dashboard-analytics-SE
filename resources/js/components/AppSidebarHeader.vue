<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Clock3, Menu } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useSidebar } from '@/composables/useSidebar';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { toggleMobile } = useSidebar();
const page = usePage<{
    appVersion: string;
    latest_snapshot: string | null;
}>();

const pageTitle = computed(() => {
    if (props.breadcrumbs.length > 0) {
        return props.breadcrumbs[props.breadcrumbs.length - 1]?.title;
    }

    if (page.url.startsWith('/ringkasan')) {
        return 'Ringkasan Kabupaten';
    }

    if (page.url.startsWith('/changelog')) {
        return 'Changelog Produk';
    }

    return 'Dashboard FASIH';
});

const pageSubtitle = computed(() => {
    if (page.url.startsWith('/ringkasan')) {
        return 'Pantau progres wilayah dalam tampilan rekap yang lebih ringkas.';
    }

    if (page.url.startsWith('/changelog')) {
        return 'Riwayat update produk, perbaikan, dan status modul terbaru.';
    }

    return 'Pusat analitik internal untuk memantau progres, snapshot, dan kualitas data lapangan.';
});

const latestSnapshotLabel = computed(() => {
    const raw = page.props.latest_snapshot;

    if (!raw) {
        return 'Belum ada snapshot';
    }

    return new Date(raw).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});
</script>

<template>
    <header
        class="border-b border-sidebar-border/70 bg-background/85 px-4 py-4 backdrop-blur md:px-6"
    >
        <div class="flex items-start gap-3">
            <button
                class="mt-1 flex items-center justify-center rounded-xl border border-sidebar-border/70 bg-card p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground focus:outline-none md:hidden"
                aria-label="Buka menu"
                @click="toggleMobile"
            >
                <Menu class="size-5" />
            </button>

            <div class="min-w-0 flex-1">
                <div
                    class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                >
                    <div class="min-w-0">
                        <h1
                            class="text-xl font-semibold tracking-tight text-foreground md:text-2xl"
                        >
                            {{ pageTitle }}
                        </h1>
                        <p
                            class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground"
                        >
                            {{ pageSubtitle }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 md:max-w-md md:justify-end"
                    >
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-sidebar-border/70 bg-card px-3 py-2 shadow-sm"
                        >
                            <p
                                class="text-[10px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                            >
                                Versi
                            </p>
                            <p class="text-xs font-semibold text-foreground">
                                v{{ page.props.appVersion }}
                            </p>
                        </div>
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-sidebar-border/70 bg-card px-3 py-2 shadow-sm"
                        >
                            <div
                                class="flex items-center gap-1.5 text-[10px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                            >
                                <Clock3 class="size-3.5" />
                                Snapshot
                            </div>
                            <p class="text-xs font-semibold text-foreground">
                                {{ latestSnapshotLabel }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="props.breadcrumbs.length > 0"
                    class="mt-4 border-t border-sidebar-border/60 pt-3"
                >
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </div>
            </div>
        </div>
    </header>
</template>
