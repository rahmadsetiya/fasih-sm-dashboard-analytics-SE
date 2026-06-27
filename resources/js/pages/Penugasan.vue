<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface WilayahNode {
    uuid: string;
    level: number;
    code: string;
    name: string;
    parent_uuid: string | null;
}

const props = defineProps<{
    db_ready: boolean;
    wilayah: Record<string, WilayahNode[]>;
    pengawas_options: { email: string; nama: string }[];
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

// ── wilayah cascade ────────────────────────────────────────────────────────
const filterKec = ref('');
const filterDes = ref('');
const filterSls = ref('');
const filterSubsls = ref('');

const w = props.wilayah as Record<string, WilayahNode[]>;
const kecOptions = computed(() => w[3] ?? []);
const desOptions = computed(() => {
    if (!filterKec.value) {
        return w[4] ?? [];
    }

    const node = kecOptions.value.find((k) => k.code === filterKec.value);

    return node
        ? (w[4] ?? []).filter((d) => d.parent_uuid === node.uuid)
        : (w[4] ?? []);
});
const slsOptions = computed(() => {
    if (!filterDes.value) {
        return [];
    }

    const node = (w[4] ?? []).find((d) => d.code === filterDes.value);

    return node ? (w[5] ?? []).filter((s) => s.parent_uuid === node.uuid) : [];
});
const subslsOptions = computed(() => {
    if (!filterSls.value) {
        return [];
    }

    const node = (w[5] ?? []).find((s) => s.code === filterSls.value);

    return node
        ? (w[6] ?? []).filter((ss) => ss.parent_uuid === node.uuid)
        : [];
});

watch(filterKec, () => {
    filterDes.value = '';
    filterSls.value = '';
    filterSubsls.value = '';
});
watch(filterDes, () => {
    filterSls.value = '';
    filterSubsls.value = '';
});
watch(filterSls, () => {
    filterSubsls.value = '';
});

// ── filter state ───────────────────────────────────────────────────────────
const filterStatus = ref('');
const filterPengawas = ref('');
const search = ref('');

// ── pagination state ───────────────────────────────────────────────────────
const loading = ref(false);
const rows = ref<Assignment[]>([]);
const total = ref(0);
const perPage = ref(20);
const page = ref(1);

const totalPages = computed(() => Math.ceil(total.value / perPage.value));

// ── history dialog ─────────────────────────────────────────────────────────
const historyOpen = ref(false);
const historyLoading = ref(false);
const historyRows = ref<HistoryRow[]>([]);
const selectedRow = ref<Assignment | null>(null);

// ── static data ────────────────────────────────────────────────────────────
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
const H = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function fetchData() {
    if (!props.db_ready) {
        return;
    }

    loading.value = true;
    const p = new URLSearchParams({
        page: String(page.value),
        per_page: String(perPage.value),
    });

    if (filterStatus.value !== '') {
        p.set('status', filterStatus.value);
    }

    if (filterKec.value) {
        p.set('kdkec', filterKec.value);
    }

    if (filterDes.value) {
        p.set('kddes', filterDes.value);
    }

    if (filterSls.value) {
        p.set('kdsls', filterSls.value);
    }

    if (filterSubsls.value) {
        p.set('kdsubsls', filterSubsls.value);
    }

    if (filterPengawas.value) {
        p.set('pengawas', filterPengawas.value);
    }

    if (search.value) {
        p.set('search', search.value);
    }

    try {
        const r = await fetch(`/api/penugasan?${p}`, { headers: H });
        const d = await r.json();
        rows.value = d.data ?? [];
        total.value = d.total ?? 0;
    } finally {
        loading.value = false;
    }
}

async function openHistory(row: Assignment) {
    selectedRow.value = row;
    historyOpen.value = true;
    historyLoading.value = true;
    historyRows.value = [];

    try {
        const r = await fetch(
            `/api/penugasan/history?id=${encodeURIComponent(row.assignment_id)}`,
            { headers: H },
        );
        historyRows.value = await r.json();
    } finally {
        historyLoading.value = false;
    }
}

function applyFilters() {
    page.value = 1;
    fetchData();
}

watch(page, fetchData);
watch(perPage, () => {
    page.value = 1;
    fetchData();
});

fetchData();

// ── mangkrak tab ───────────────────────────────────────────────────────────
const activeView = ref<'list' | 'mangkrak'>('list');
interface MangkrakRow {
    assignment_id: string;
    code_identity: string;
    pencacah_nama: string | null;
    pencacah_email: string | null;
    pengawas_nama: string | null;
    status: string;
    assignment_status_id: number;
    date_modified: string;
    days_stale: number;
    nmkec: string | null;
    nmdes: string | null;
}
const mangkrakRows = ref<MangkrakRow[]>([]);
const mangkrakTotal = ref(0);
const mangkrakLoading = ref(false);
const mangkrakThreshold = ref(7);

async function fetchMangkrak() {
    if (!props.db_ready) {
        return;
    }

    mangkrakLoading.value = true;
    const p = new URLSearchParams({
        threshold_days: String(mangkrakThreshold.value),
    });

    if (filterKec.value) {
        p.set('kdkec', filterKec.value);
    }

    if (filterDes.value) {
        p.set('kddes', filterDes.value);
    }

    if (filterSls.value) {
        p.set('kdsls', filterSls.value);
    }

    if (filterSubsls.value) {
        p.set('kdsubsls', filterSubsls.value);
    }

    if (filterPengawas.value) {
        p.set('pengawas', filterPengawas.value);
    }

    try {
        const r = await fetch(`/api/penugasan/mangkrak?${p}`, { headers: H });
        const d = await r.json();
        mangkrakRows.value = d.data ?? [];
        mangkrakTotal.value = d.total ?? 0;
    } finally {
        mangkrakLoading.value = false;
    }
}

watch(activeView, (v) => {
    if (v === 'mangkrak') {
        fetchMangkrak();
    }
});
watch(mangkrakThreshold, () => {
    if (activeView.value === 'mangkrak') {
        fetchMangkrak();
    }
});

function staleClass(days: number) {
    if (days >= 14) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
    }

    if (days >= 7) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
    }

    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
}

function fmtDate(s: string | null) {
    if (!s) {
        return '—';
    }

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
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold">Daftar Penugasan</h1>
                <p class="text-xs text-muted-foreground">
                    {{ total.toLocaleString('id') }} penugasan &mdash; klik
                    baris untuk lihat history
                </p>
            </div>
            <div class="flex gap-1 rounded-lg border bg-muted/40 p-1">
                <button
                    v-for="v in [
                        { id: 'list', label: 'Semua' },
                        { id: 'mangkrak', label: 'Mangkrak' },
                    ]"
                    :key="v.id"
                    :class="[
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        activeView === v.id
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    ]"
                    @click="activeView = v.id as typeof activeView"
                >
                    {{ v.label }}
                </button>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Status</label
                >
                <select
                    v-model="filterStatus"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option v-for="s in statuses" :key="s.id" :value="s.id">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Kecamatan</label
                >
                <select
                    v-model="filterKec"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">Semua</option>
                    <option
                        v-for="k in kecOptions"
                        :key="k.code"
                        :value="k.code"
                    >
                        {{ k.name }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Desa</label
                >
                <select
                    v-model="filterDes"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">Semua</option>
                    <option
                        v-for="d in desOptions"
                        :key="d.code"
                        :value="d.code"
                    >
                        {{ d.name }}
                    </option>
                </select>
            </div>
            <div v-if="filterDes" class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >SLS</label
                >
                <select
                    v-model="filterSls"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">Semua</option>
                    <option
                        v-for="s in slsOptions"
                        :key="s.code"
                        :value="s.code"
                    >
                        {{ s.name }}
                    </option>
                </select>
            </div>
            <div v-if="filterSls" class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Sub-SLS</label
                >
                <select
                    v-model="filterSubsls"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">Semua</option>
                    <option
                        v-for="ss in subslsOptions"
                        :key="ss.code"
                        :value="ss.code"
                    >
                        {{ ss.name }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Pengawas</label
                >
                <select
                    v-model="filterPengawas"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">Semua</option>
                    <option
                        v-for="p in pengawas_options"
                        :key="p.email"
                        :value="p.email"
                    >
                        {{ p.nama }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-medium text-muted-foreground"
                    >Cari Kode</label
                >
                <input
                    v-model="search"
                    type="text"
                    placeholder="Kode penugasan..."
                    class="h-8 w-44 rounded-md border border-input bg-background px-2 text-xs placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
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
                @click="
                    () => {
                        filterStatus = '';
                        filterKec = '';
                        filterDes = '';
                        filterSls = '';
                        filterSubsls = '';
                        filterPengawas = '';
                        search = '';
                        applyFilters();
                    }
                "
            >
                Reset
            </button>
        </div>

        <!-- List view only -->
        <template v-if="activeView === 'list'">
            <!-- Per-page + total -->
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted-foreground"
                    >{{ total.toLocaleString('id') }} penugasan</span
                >
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground"
                        >Per halaman:</span
                    >
                    <select
                        v-model="perPage"
                        class="h-7 rounded border border-input bg-background px-1 text-xs"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                #
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Kode
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Pencacah
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Pengawas
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Wilayah
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Status
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Diperbarui
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-if="loading">
                            <td
                                colspan="7"
                                class="py-8 text-center text-muted-foreground"
                            >
                                Memuat data...
                            </td>
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
                                <td class="px-3 py-2 font-mono text-[10px]">
                                    {{ row.code_identity }}
                                </td>
                                <td class="px-3 py-2">
                                    <div>{{ row.pencacah_nama ?? '—' }}</div>
                                    <div
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ row.pencacah_email ?? '' }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ row.pengawas_nama ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <div>{{ row.nmkec ?? row.kdkec }}</div>
                                    <div
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ row.nmdes ?? row.kddes }}
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        :class="[
                                            'rounded px-1.5 py-0.5 font-medium',
                                            STATUS_BADGE[
                                                row.assignment_status_id
                                            ] ?? 'bg-gray-100 text-gray-700',
                                        ]"
                                        >{{ row.status }}</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ fmtDate(row.date_modified) }}
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td
                                    colspan="7"
                                    class="py-8 text-center text-muted-foreground"
                                >
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
                <span
                    >{{ ((page - 1) * perPage + 1).toLocaleString('id') }}–{{
                        Math.min(page * perPage, total).toLocaleString('id')
                    }}
                    dari {{ total.toLocaleString('id') }}</span
                >
                <div class="flex items-center gap-1">
                    <button
                        :disabled="page === 1"
                        class="rounded border px-2 py-1 hover:bg-muted/40 disabled:opacity-40"
                        @click="page--"
                    >
                        ‹
                    </button>
                    <span class="px-2 py-1">{{ page }} / {{ totalPages }}</span>
                    <button
                        :disabled="page >= totalPages"
                        class="rounded border px-2 py-1 hover:bg-muted/40 disabled:opacity-40"
                        @click="page++"
                    >
                        ›
                    </button>
                </div>
            </div> </template
        ><!-- end list view -->
    </div>

    <!-- Mangkrak panel -->
    <template v-if="activeView === 'mangkrak'">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-xs text-muted-foreground"
                >Tidak diubah selama ≥</span
            >
            <div class="flex gap-1">
                <button
                    v-for="d in [3, 7, 14]"
                    :key="d"
                    :class="[
                        'rounded border px-2 py-1 text-xs font-medium transition-colors',
                        mangkrakThreshold === d
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-background text-muted-foreground hover:text-foreground',
                    ]"
                    @click="mangkrakThreshold = d"
                >
                    {{ d }} hari
                </button>
            </div>
            <span class="text-xs text-muted-foreground"
                >{{ mangkrakTotal.toLocaleString('id') }} assignment mangkrak
                (maks 200)</span
            >
        </div>
        <div
            v-if="mangkrakLoading"
            class="flex h-32 items-center justify-center text-sm text-muted-foreground"
        >
            Memuat...
        </div>
        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-xs">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            #
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Kode
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Pencacah
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Pengawas
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Wilayah
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Status
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Terakhir Diubah
                        </th>
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground"
                        >
                            Mangkrak
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="!mangkrakRows.length">
                        <td
                            colspan="8"
                            class="py-8 text-center text-muted-foreground"
                        >
                            Tidak ada assignment mangkrak
                        </td>
                    </tr>
                    <tr
                        v-for="(r, i) in mangkrakRows"
                        :key="r.assignment_id"
                        class="hover:bg-muted/40"
                        @click="openHistory(r as unknown as Assignment)"
                    >
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ i + 1 }}
                        </td>
                        <td class="px-3 py-2 font-mono text-[10px]">
                            {{ r.code_identity }}
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ r.pencacah_nama ?? '—' }}</div>
                            <div class="text-[10px] text-muted-foreground">
                                {{ r.pencacah_email ?? '' }}
                            </div>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ r.pengawas_nama ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ r.nmkec ?? '—' }}</div>
                            <div class="text-[10px] text-muted-foreground">
                                {{ r.nmdes ?? '' }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                :class="[
                                    'rounded px-1.5 py-0.5 font-medium',
                                    STATUS_BADGE[r.assignment_status_id] ??
                                        'bg-gray-100 text-gray-700',
                                ]"
                                >{{ r.status }}</span
                            >
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ fmtDate(r.date_modified) }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                :class="[
                                    'rounded px-1.5 py-0.5 font-medium',
                                    staleClass(r.days_stale),
                                ]"
                                >{{ r.days_stale }} hari</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>

    <!-- History Dialog -->
    <Teleport to="body">
        <div
            v-if="historyOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="historyOpen = false"
        >
            <div
                class="w-full max-w-lg rounded-xl border bg-background shadow-xl"
            >
                <div
                    class="flex items-start justify-between border-b px-4 py-3"
                >
                    <div>
                        <p class="text-sm font-semibold">History Status</p>
                        <p class="font-mono text-[10px] text-muted-foreground">
                            {{ selectedRow?.code_identity }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ selectedRow?.pencacah_nama ?? '' }}
                        </p>
                    </div>
                    <button
                        class="mt-0.5 rounded-md p-1 text-muted-foreground hover:bg-muted"
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
                        Memuat...
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
                            <span
                                class="absolute -left-1.5 mt-0.5 h-3 w-3 rounded-full border-2 border-background bg-primary"
                            />
                            <div class="text-xs">
                                <div
                                    class="flex items-center gap-1.5 font-medium"
                                >
                                    <span
                                        v-if="h.from_status"
                                        class="text-muted-foreground line-through"
                                        >{{ h.from_status }}</span
                                    >
                                    <span
                                        v-if="h.from_status"
                                        class="text-muted-foreground"
                                        >→</span
                                    >
                                    <span
                                        >{{ STATUS_ICON[h.to_status] ?? '' }}
                                        {{ h.to_status }}</span
                                    >
                                </div>
                                <p class="mt-0.5 text-muted-foreground">
                                    {{ fmtDate(h.change_date) }}
                                </p>
                                <p
                                    v-if="h.pencacah_email"
                                    class="text-[10px] text-muted-foreground"
                                >
                                    Pencacah: {{ h.pencacah_email }}
                                </p>
                                <p
                                    v-if="h.pengawas_email"
                                    class="text-[10px] text-muted-foreground"
                                >
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
