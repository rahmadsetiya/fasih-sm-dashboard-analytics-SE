<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps<{
    db_ready: boolean;
    kec_options: { code: string; name: string }[];
}>();

// ── types ─────────────────────────────────────────────────────────────────
interface Assignment {
    assignment_id: string;
    code_identity: string;
    pencacah_nama: string | null;
    pencacah_email: string | null;
    pengawas_nama: string | null;
    status: string;
    assignment_status_id: number;
    date_modified: string;
    nmkec: string | null;
    kdkec: string;
    nmdes: string | null;
    kddes: string;
}

interface HistoryRow {
    id: number;
    from_status: string | null;
    to_status: string;
    change_date: string;
    pencacah_email: string | null;
    pengawas_email: string | null;
}

// ── state ─────────────────────────────────────────────────────────────────
const loading = ref(false);
const rows = ref<Assignment[]>([]);
const total = ref(0);
const perPage = ref(50);
const page = ref(1);

const filterStatus = ref('');
const filterKec = ref('');
const search = ref('');

const historyOpen = ref(false);
const historyLoading = ref(false);
const historyRows = ref<HistoryRow[]>([]);
const selectedAssignment = ref<Assignment | null>(null);

// ── computed ───────────────────────────────────────────────────────────────
const totalPages = computed(() => Math.ceil(total.value / perPage.value));

const statuses = [
    { id: '', label: 'Semua Status' },
    { id: '0', label: 'DRAFT' },
    { id: '1', label: 'SUBMITTED BY Pencacah' },
    { id: '2', label: 'APPROVED BY Pengawas' },
    { id: '3', label: 'REJECTED BY Pengawas' },
    { id: '5', label: 'SUBMITTED RESPONDENT' },
];

const STATUS_BADGE: Record<number, string> = {
    0: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    1: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    2: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    3: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    5: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
};

const STATUS_ICON: Record<string, string> = {
    DRAFT: '📝',
    'SUBMITTED BY Pencacah': '📤',
    'APPROVED BY Pengawas': '✅',
    'REJECTED BY Pengawas': '❌',
    'SUBMITTED RESPONDENT': '📬',
};

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchData() {
    if (!props.db_ready) return;
    loading.value = true;

    const params = new URLSearchParams({ page: String(page.value) });
    if (filterStatus.value !== '') params.set('status', filterStatus.value);
    if (filterKec.value) params.set('kdkec', filterKec.value);
    if (search.value) params.set('search', search.value);

    try {
        const res = await fetch(`/api/penugasan?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const d = await res.json();
        rows.value = d.data ?? [];
        total.value = d.total ?? 0;
        perPage.value = d.per_page ?? 50;
    } finally {
        loading.value = false;
    }
}

async function openHistory(row: Assignment) {
    selectedAssignment.value = row;
    historyOpen.value = true;
    historyLoading.value = true;
    historyRows.value = [];

    try {
        const res = await fetch(`/api/penugasan/history?id=${encodeURIComponent(row.assignment_id)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        historyRows.value = await res.json();
    } finally {
        historyLoading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    fetchData();
}

watch(page, fetchData);

fetchData();

// ── helpers ────────────────────────────────────────────────────────────────
function fmtDate(s: string | null): string {
    if (!s) return '—';
    return new Date(s).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Daftar Penugasan" />

    <div
        v-if="!db_ready"
        class="flex h-64 items-center justify-center text-sm text-muted-foreground"
    >
        Import database FASIH terlebih dahulu.
    </div>

    <div v-else class="space-y-3 p-4">
        <!-- Header -->
        <div>
            <h1 class="text-lg font-semibold">Daftar Penugasan</h1>
            <p class="text-xs text-muted-foreground">
                {{ total.toLocaleString('id') }} penugasan &mdash; klik baris untuk lihat history
            </p>
        </div>

        <!-- Filter bar -->
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground">Status</label>
                <select
                    v-model="filterStatus"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                    <option v-for="s in statuses" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground">Kecamatan</label>
                <select
                    v-model="filterKec"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                    <option value="">Semua</option>
                    <option v-for="k in kec_options" :key="k.code" :value="k.code">
                        {{ k.name }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground">Cari Kode</label>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Kode penugasan..."
                    class="h-8 w-48 rounded-md border border-input bg-background px-2 text-xs placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    @keydown.enter="applyFilters"
                />
            </div>
            <button
                class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                @click="applyFilters"
            >
                Terapkan
            </button>
            <button
                class="h-8 rounded-md border border-input bg-background px-3 text-xs text-muted-foreground transition-colors hover:text-foreground"
                @click="() => { filterStatus = ''; filterKec = ''; search = ''; applyFilters(); }"
            >
                Reset
            </button>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-xs">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Kode Penugasan</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Pencacah</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Wilayah</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Diperbarui</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="loading">
                        <td colspan="6" class="py-8 text-center text-muted-foreground">Memuat data...</td>
                    </tr>
                    <template v-else>
                        <tr
                            v-for="(row, i) in rows"
                            :key="row.assignment_id"
                            class="cursor-pointer hover:bg-muted/40"
                            @click="openHistory(row)"
                        >
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ (page - 1) * perPage + i + 1 }}
                            </td>
                            <td class="px-3 py-2 font-mono text-[10px]">{{ row.code_identity }}</td>
                            <td class="px-3 py-2">
                                <div>{{ row.pencacah_nama ?? '—' }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ row.pencacah_email ?? '' }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ row.nmkec ?? row.kdkec }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ row.nmdes ?? row.kddes }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    :class="[
                                        'rounded px-1.5 py-0.5 font-medium',
                                        STATUS_BADGE[row.assignment_status_id] ?? 'bg-gray-100 text-gray-700',
                                    ]"
                                >{{ row.status }}</span>
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">{{ fmtDate(row.date_modified) }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="6" class="py-8 text-center text-muted-foreground">
                                Tidak ada penugasan ditemukan
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div
            v-if="totalPages > 1"
            class="flex items-center justify-between text-xs text-muted-foreground"
        >
            <span>
                {{ ((page - 1) * perPage + 1).toLocaleString('id') }}–{{ Math.min(page * perPage, total).toLocaleString('id') }}
                dari {{ total.toLocaleString('id') }}
            </span>
            <div class="flex items-center gap-1">
                <button
                    :disabled="page === 1"
                    class="rounded border px-2 py-1 transition-colors hover:bg-muted/40 disabled:opacity-40"
                    @click="page--"
                >‹</button>
                <span class="px-2 py-1">{{ page }} / {{ totalPages }}</span>
                <button
                    :disabled="page >= totalPages"
                    class="rounded border px-2 py-1 transition-colors hover:bg-muted/40 disabled:opacity-40"
                    @click="page++"
                >›</button>
            </div>
        </div>
    </div>

    <!-- ── History Dialog ──────────────────────────────────────────────── -->
    <Teleport to="body">
        <div
            v-if="historyOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="historyOpen = false"
        >
            <div class="w-full max-w-lg rounded-xl border bg-background shadow-xl">
                <div class="flex items-start justify-between border-b px-4 py-3">
                    <div>
                        <p class="text-sm font-semibold">History Status</p>
                        <p class="font-mono text-[10px] text-muted-foreground">
                            {{ selectedAssignment?.code_identity }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ selectedAssignment?.pencacah_nama ?? '' }}
                        </p>
                    </div>
                    <button
                        class="mt-0.5 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted"
                        @click="historyOpen = false"
                    >
                        ✕
                    </button>
                </div>

                <div class="max-h-96 overflow-y-auto p-4">
                    <div
                        v-if="historyLoading"
                        class="flex h-24 items-center justify-center text-sm text-muted-foreground"
                    >
                        Memuat history...
                    </div>
                    <div
                        v-else-if="!historyRows.length"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Tidak ada history
                    </div>
                    <ol v-else class="relative border-l border-border pl-5">
                        <li
                            v-for="h in historyRows"
                            :key="h.id"
                            class="mb-5 ml-1"
                        >
                            <span class="absolute -left-1.5 mt-0.5 h-3 w-3 rounded-full border-2 border-background bg-primary" />
                            <div class="text-xs">
                                <div class="flex items-center gap-1.5 font-medium">
                                    <span v-if="h.from_status" class="text-muted-foreground line-through">
                                        {{ h.from_status }}
                                    </span>
                                    <span v-if="h.from_status" class="text-muted-foreground">→</span>
                                    <span>{{ STATUS_ICON[h.to_status] ?? '' }} {{ h.to_status }}</span>
                                </div>
                                <p class="mt-0.5 text-muted-foreground">{{ fmtDate(h.change_date) }}</p>
                                <p v-if="h.pencacah_email" class="text-[10px] text-muted-foreground">
                                    Pencacah: {{ h.pencacah_email }}
                                </p>
                                <p v-if="h.pengawas_email" class="text-[10px] text-muted-foreground">
                                    Pengawas: {{ h.pengawas_email }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </Teleport>
</template>
