<script setup lang="ts">
import { computed, ref } from 'vue';

interface RankingRow {
    key: string;
    label: string;
    progress_pct: number;
    approved_pct: number;
}

const props = defineProps<{
    rows: RankingRow[];
}>();

const DEFAULT_VISIBLE_ROWS = 7;
const expanded = ref(false);

const visibleRows = computed(() =>
    expanded.value ? props.rows : props.rows.slice(0, DEFAULT_VISIBLE_ROWS),
);

const canExpand = computed(() => props.rows.length > DEFAULT_VISIBLE_ROWS);

function clampPercent(value: number): number {
    return Math.min(100, Math.max(0, value));
}

function formatPercent(value: number): string {
    return value.toLocaleString('id-ID', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
}
</script>

<template>
    <div>
        <p class="mb-3 text-xs text-muted-foreground" aria-live="polite">
            Menampilkan {{ visibleRows.length }} dari {{ rows.length }} wilayah
        </p>

        <ol
            class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/30"
        >
            <li
                v-for="(row, index) in visibleRows"
                :key="row.key"
                class="py-3 first:pt-0"
            >
                <div class="mb-2 flex min-w-0 items-start gap-2">
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground tabular-nums"
                    >
                        {{ index + 1 }}
                    </span>
                    <span class="min-w-0 text-xs leading-5 font-medium">
                        {{ row.label }}
                    </span>
                </div>

                <div class="space-y-1.5 pl-7">
                    <div
                        class="grid grid-cols-[3.75rem_minmax(0,1fr)_3.25rem] items-center gap-2"
                    >
                        <span class="text-[10px] text-muted-foreground"
                            >Progress</span
                        >
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-label="`Progress ${row.label}`"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-valuenow="clampPercent(row.progress_pct)"
                            :aria-valuetext="`${formatPercent(row.progress_pct)} persen`"
                        >
                            <div
                                class="h-full rounded-full bg-[#FFA95A]"
                                :style="{
                                    width: `${clampPercent(row.progress_pct)}%`,
                                }"
                            />
                        </div>
                        <span
                            class="text-right text-[10px] font-semibold tabular-nums"
                            >{{ formatPercent(row.progress_pct) }}%</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-[3.75rem_minmax(0,1fr)_3.25rem] items-center gap-2"
                    >
                        <span class="text-[10px] text-muted-foreground"
                            >Approved</span
                        >
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-label="`Approved ${row.label}`"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-valuenow="clampPercent(row.approved_pct)"
                            :aria-valuetext="`${formatPercent(row.approved_pct)} persen`"
                        >
                            <div
                                class="h-full rounded-full bg-green-500"
                                :style="{
                                    width: `${clampPercent(row.approved_pct)}%`,
                                }"
                            />
                        </div>
                        <span
                            class="text-right text-[10px] font-semibold tabular-nums"
                            >{{ formatPercent(row.approved_pct) }}%</span
                        >
                    </div>
                </div>
            </li>
        </ol>

        <button
            v-if="canExpand"
            type="button"
            class="mt-3 w-full rounded-lg border border-sidebar-border/70 px-3 py-2 text-xs font-medium transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            :aria-expanded="expanded"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Ringkas ke Top 7' : `Tampilkan Top ${rows.length}` }}
        </button>
    </div>
</template>
