<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useDark } from '@vueuse/core';
import { ref, computed, watch, onMounted } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps<{
    db_ready: boolean;
    kec_options: { code: string; name: string }[];
}>();

// ── types ─────────────────────────────────────────────────────────────────
interface ProporsiRow {
    group_code: string; group_label: string; total: number;
    approved: number; submitted: number; rejected: number;
    p_approved: number; ci_lower: number; ci_upper: number; margin_of_error: number;
}
interface GroupStat { code: string; n: number; approved: number; p: number; }
interface KomparasiResult {
    group_a: GroupStat; group_b: GroupStat;
    z: number; p_value: number; significant: boolean; interpretasi: string; error?: string;
}
interface Chi2Result {
    group_by: string; col_labels: string[]; col_keys: string[];
    table: Record<string, number | string>[]; col_sums: Record<string, number>;
    grand_total: number; chi2: number; df: number; p_value: number;
    significant: boolean; interpretasi: string; error?: string;
}
interface KorelasiPoint { nama: string; total: number; avg_error: number; rejection_rate: number; }
interface KorelasiResult {
    points: KorelasiPoint[]; n: number;
    r: number | null; r2: number | null; t: number | null; p_value: number | null;
    significant: boolean; interpretasi: string; error?: string;
}

// ── state ─────────────────────────────────────────────────────────────────
const isDark    = useDark();
const activeTab = ref<'proporsi' | 'komparasi' | 'chi2' | 'korelasi'>('proporsi');
const loading   = ref(false);

const proporsiGroup = ref<'kecamatan' | 'gelombang' | 'kelas' | 'tc'>('kecamatan');
const proporsiData  = ref<ProporsiRow[]>([]);

const kompGroupBy = ref<'kecamatan' | 'gelombang' | 'kelas' | 'tc'>('kecamatan');
const kompGroupA  = ref('');
const kompGroupB  = ref('');
const kompResult  = ref<KomparasiResult | null>(null);
const kompGroups  = computed(() => {
    if (kompGroupBy.value === 'kecamatan') return props.kec_options.map(k => ({ code: k.code, label: k.name }));
    const opts: Record<string, string[]> = {
        gelombang: ['I', 'II'], kelas: ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
        tc: ['grand zidny', 'wifadelia', 'sabindo'],
    };
    return (opts[kompGroupBy.value] ?? []).map(l => ({ code: l, label: l }));
});

const chi2GroupBy = ref<'gelombang' | 'kelas' | 'tc'>('gelombang');
const chi2Result  = ref<Chi2Result | null>(null);
const korelasiResult = ref<KorelasiResult | null>(null);

// ── fetch ─────────────────────────────────────────────────────────────────
const H = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function fetchProporsi() {
    loading.value = true;
    try {
        const r = await fetch(`/api/statistik/proporsi?group_by=${proporsiGroup.value}`, { headers: H });
        proporsiData.value = await r.json();
    } finally { loading.value = false; }
}

async function runKomparasi() {
    if (!kompGroupA.value || !kompGroupB.value) return;
    loading.value = true;
    try {
        const p = new URLSearchParams({ group_by: kompGroupBy.value, group_a: kompGroupA.value, group_b: kompGroupB.value });
        const r = await fetch(`/api/statistik/komparasi?${p}`, { headers: H });
        kompResult.value = await r.json();
    } finally { loading.value = false; }
}

async function fetchChi2() {
    loading.value = true;
    try {
        const r = await fetch(`/api/statistik/chi2?group_by=${chi2GroupBy.value}`, { headers: H });
        chi2Result.value = await r.json();
    } finally { loading.value = false; }
}

async function fetchKorelasi() {
    loading.value = true;
    try {
        const r = await fetch('/api/statistik/korelasi', { headers: H });
        korelasiResult.value = await r.json();
    } finally { loading.value = false; }
}

onMounted(() => { if (props.db_ready) fetchProporsi(); });
watch(activeTab, tab => {
    if (tab === 'proporsi') fetchProporsi();
    else if (tab === 'chi2') fetchChi2();
    else if (tab === 'korelasi') fetchKorelasi();
});
watch(proporsiGroup, fetchProporsi);
watch(chi2GroupBy, fetchChi2);
watch(kompGroupBy, () => { kompGroupA.value = ''; kompGroupB.value = ''; kompResult.value = null; });

// ── charts ─────────────────────────────────────────────────────────────────
const cc = computed(() => isDark.value ? '#9ca3af' : '#6b7280');

const proporsiChartOptions = computed(() => ({
    chart: { type: 'bar' as const, background: 'transparent', toolbar: { show: false }, animations: { enabled: false } },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: proporsiData.value.map(r => r.group_label), labels: { style: { colors: cc.value, fontSize: '10px' }, rotate: -30 } },
    yaxis: { max: 100, labels: { formatter: (v: number) => `${v}%`, style: { colors: cc.value } } },
    tooltip: { y: { formatter: (v: number) => `${v}%` } },
    colors: ['#10b981', '#f59e0b'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
    legend: { labels: { colors: cc.value } },
}));
const proporsiSeries = computed(() => [
    { name: 'Approved %', data: proporsiData.value.map(r => r.p_approved) },
    { name: 'Margin of Error', data: proporsiData.value.map(r => r.margin_of_error) },
]);

const korelasiChartOptions = computed(() => ({
    chart: { type: 'scatter' as const, background: 'transparent', toolbar: { show: false }, animations: { enabled: false } },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    xaxis: { title: { text: 'Avg Error', style: { color: cc.value } }, labels: { style: { colors: cc.value } } },
    yaxis: { title: { text: 'Rejection Rate (%)', style: { color: cc.value } }, labels: { style: { colors: cc.value } } },
    colors: ['#6366f1'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
}));
const korelasiSeries = computed(() => [{
    name: 'Pencacah',
    data: (korelasiResult.value?.points ?? []).map(p => [p.avg_error, p.rejection_rate]),
}]);
</script>

<template>
    <Head title="Statistik Inferensia" />

    <div v-if="!db_ready" class="flex h-64 items-center justify-center text-sm text-muted-foreground">
        Import database FASIH terlebih dahulu.
    </div>

    <div v-else class="space-y-4 p-4">
        <div>
            <h1 class="text-lg font-semibold">Statistik Inferensia</h1>
            <p class="text-xs text-muted-foreground">Analisis statistik dari data penugasan sensus</p>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 rounded-lg border bg-muted/40 p-1">
            <button v-for="tab in [{id:'proporsi',label:'Estimasi Proporsi'},{id:'komparasi',label:'Uji Beda Dua Kelompok'},{id:'chi2',label:'Uji Chi-Squared'},{id:'korelasi',label:'Korelasi'}]"
                :key="tab.id"
                :class="['flex-1 rounded-md px-2 py-1.5 text-xs font-medium transition-colors',
                    activeTab === tab.id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']"
                @click="activeTab = tab.id as typeof activeTab">
                {{ tab.label }}
            </button>
        </div>

        <div v-if="loading" class="flex h-48 items-center justify-center text-sm text-muted-foreground">Menghitung...</div>

        <!-- ── Estimasi Proporsi ───────────────────────────────────── -->
        <template v-else-if="activeTab === 'proporsi'">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs text-muted-foreground">Kelompokkan by:</span>
                <button v-for="g in [{id:'kecamatan',label:'Kecamatan'},{id:'gelombang',label:'Gelombang'},{id:'kelas',label:'Kelas'},{id:'tc',label:'TC'}]"
                    :key="g.id"
                    :class="['rounded-md border px-2.5 py-1 text-xs font-medium transition-colors',
                        proporsiGroup === g.id ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-muted-foreground hover:text-foreground']"
                    @click="proporsiGroup = g.id as typeof proporsiGroup">{{ g.label }}</button>
            </div>
            <div class="rounded-lg border bg-blue-50/50 p-3 text-xs text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                <strong>Metode:</strong> Proporsi assignment Approved + interval kepercayaan 95% (1.96 × √(p(1−p)/n)).
            </div>
            <div v-if="proporsiData.length" class="rounded-lg border p-3">
                <VueApexCharts type="bar" :height="240" :options="proporsiChartOptions" :series="proporsiSeries" />
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Kelompok</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">n</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Approved</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">p̂ (%)</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">CI 95% [Lower, Upper]</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">±ME</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in proporsiData" :key="row.group_code" class="hover:bg-muted/30">
                            <td class="px-3 py-2 font-medium">{{ row.group_label }}</td>
                            <td class="px-3 py-2 text-right">{{ row.total.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.approved.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ row.p_approved }}%</td>
                            <td class="px-3 py-2 text-right text-muted-foreground">[{{ row.ci_lower }}%, {{ row.ci_upper }}%]</td>
                            <td class="px-3 py-2 text-right text-amber-600 dark:text-amber-400">±{{ row.margin_of_error }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── Uji Beda Dua Kelompok ──────────────────────────────── -->
        <template v-else-if="activeTab === 'komparasi'">
            <div class="rounded-lg border bg-blue-50/50 p-3 text-xs text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                <strong>Metode:</strong> Uji Z dua proporsi independen (H₀: p₁ = p₂). Signifikan jika p-value &lt; 0.05.
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1">
                    <label class="text-[10px] font-medium text-muted-foreground">Variabel</label>
                    <select v-model="kompGroupBy" class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="kecamatan">Kecamatan</option>
                        <option value="gelombang">Gelombang</option>
                        <option value="kelas">Kelas</option>
                        <option value="tc">Training Center</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[10px] font-medium text-muted-foreground">Kelompok A</label>
                    <select v-model="kompGroupA" class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="">Pilih...</option>
                        <option v-for="g in kompGroups.filter(g => g.code !== kompGroupB)" :key="g.code" :value="g.code">{{ g.label }}</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[10px] font-medium text-muted-foreground">Kelompok B</label>
                    <select v-model="kompGroupB" class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="">Pilih...</option>
                        <option v-for="g in kompGroups.filter(g => g.code !== kompGroupA)" :key="g.code" :value="g.code">{{ g.label }}</option>
                    </select>
                </div>
                <button :disabled="!kompGroupA || !kompGroupB"
                    class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                    @click="runKomparasi">Hitung</button>
            </div>

            <div v-if="kompResult">
                <div v-if="kompResult.error" class="rounded-lg border border-red-300 bg-red-50 p-3 text-xs text-red-700 dark:bg-red-950/30 dark:text-red-300">{{ kompResult.error }}</div>
                <div v-else class="space-y-3">
                    <div :class="['rounded-lg border p-3', kompResult.significant ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/30' : 'border-amber-400 bg-amber-50 dark:bg-amber-950/30']">
                        <p :class="['text-sm font-semibold', kompResult.significant ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300']">
                            {{ kompResult.significant ? '✅ Signifikan' : '⚠️ Tidak Signifikan' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ kompResult.interpretasi }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div v-for="g in (['group_a', 'group_b'] as const)" :key="g" class="rounded-lg border p-3">
                            <p class="text-xs font-semibold">{{ g === 'group_a' ? 'A' : 'B' }}: {{ kompResult[g].code }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">n={{ kompResult[g].n.toLocaleString('id') }}, Approved={{ kompResult[g].approved.toLocaleString('id') }} ({{ kompResult[g].p }}%)</p>
                        </div>
                    </div>
                    <div class="flex gap-6 rounded-lg border p-3 text-xs">
                        <span><span class="text-muted-foreground">Z:</span> <span class="font-mono font-medium">{{ kompResult.z }}</span></span>
                        <span><span class="text-muted-foreground">p-value:</span> <span class="font-mono font-medium">{{ kompResult.p_value }}</span></span>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Uji Chi-Squared ────────────────────────────────────── -->
        <template v-else-if="activeTab === 'chi2'">
            <div class="rounded-lg border bg-blue-50/50 p-3 text-xs text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                <strong>Metode:</strong> Uji χ² untuk asosiasi antara variabel grup dan distribusi status. Signifikan jika p-value &lt; 0.05.
            </div>
            <div class="flex gap-2">
                <button v-for="g in [{id:'gelombang',label:'Gelombang'},{id:'kelas',label:'Kelas'},{id:'tc',label:'TC'}]" :key="g.id"
                    :class="['rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                        chi2GroupBy === g.id ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-muted-foreground hover:text-foreground']"
                    @click="chi2GroupBy = g.id as typeof chi2GroupBy">{{ g.label }}</button>
            </div>

            <div v-if="chi2Result">
                <div v-if="chi2Result.error" class="rounded-lg border border-red-300 bg-red-50 p-3 text-xs text-red-700">{{ chi2Result.error }}</div>
                <div v-else class="space-y-3">
                    <div :class="['rounded-lg border p-3', chi2Result.significant ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/30' : 'border-amber-400 bg-amber-50 dark:bg-amber-950/30']">
                        <p :class="['text-sm font-semibold', chi2Result.significant ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300']">
                            {{ chi2Result.significant ? '✅ Signifikan' : '⚠️ Tidak Signifikan' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ chi2Result.interpretasi }}</p>
                        <div class="mt-2 flex gap-6 text-xs">
                            <span><span class="text-muted-foreground">χ²:</span> <span class="font-mono font-medium">{{ chi2Result.chi2 }}</span></span>
                            <span><span class="text-muted-foreground">df:</span> <span class="font-mono font-medium">{{ chi2Result.df }}</span></span>
                            <span><span class="text-muted-foreground">p-value:</span> <span class="font-mono font-medium">{{ chi2Result.p_value }}</span></span>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-xs">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">{{ chi2Result.group_by }}</th>
                                    <th v-for="label in chi2Result.col_labels" :key="label" class="px-3 py-2 text-right font-medium text-muted-foreground">{{ label }}</th>
                                    <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="row in chi2Result.table" :key="String(row.grp)" class="hover:bg-muted/30">
                                    <td class="px-3 py-2 font-medium">{{ row.grp }}</td>
                                    <td v-for="col in chi2Result.col_keys" :key="col" class="px-3 py-2 text-right">{{ Number(row[col]).toLocaleString('id') }}</td>
                                    <td class="px-3 py-2 text-right font-medium">{{ Number(row.total).toLocaleString('id') }}</td>
                                </tr>
                                <tr class="bg-muted/30 font-medium">
                                    <td class="px-3 py-2 text-muted-foreground">Total</td>
                                    <td v-for="col in chi2Result.col_keys" :key="col" class="px-3 py-2 text-right">{{ Number(chi2Result.col_sums[col]).toLocaleString('id') }}</td>
                                    <td class="px-3 py-2 text-right">{{ Number(chi2Result.grand_total).toLocaleString('id') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Korelasi ────────────────────────────────────────────── -->
        <template v-else-if="activeTab === 'korelasi'">
            <div class="rounded-lg border bg-blue-50/50 p-3 text-xs text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                <strong>Metode:</strong> Korelasi Pearson (r) antara rata-rata sum_error dan tingkat penolakan per pencacah (min. 5 penugasan).
            </div>
            <div v-if="korelasiResult">
                <div v-if="korelasiResult.error" class="rounded-lg border border-red-300 bg-red-50 p-3 text-xs text-red-700">{{ korelasiResult.error }}</div>
                <div v-else class="space-y-3">
                    <div :class="['rounded-lg border p-3', korelasiResult.significant ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/30' : 'border-amber-400 bg-amber-50 dark:bg-amber-950/30']">
                        <p :class="['text-sm font-semibold', korelasiResult.significant ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300']">
                            {{ korelasiResult.significant ? '✅ Signifikan' : '⚠️ Tidak Signifikan' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ korelasiResult.interpretasi }}</p>
                        <div v-if="korelasiResult.r !== null" class="mt-2 flex flex-wrap gap-6 text-xs">
                            <span><span class="text-muted-foreground">r:</span> <span class="font-mono font-medium">{{ korelasiResult.r }}</span></span>
                            <span><span class="text-muted-foreground">R²:</span> <span class="font-mono font-medium">{{ korelasiResult.r2 }}</span></span>
                            <span><span class="text-muted-foreground">t:</span> <span class="font-mono font-medium">{{ korelasiResult.t }}</span></span>
                            <span><span class="text-muted-foreground">p-value:</span> <span class="font-mono font-medium">{{ korelasiResult.p_value }}</span></span>
                            <span><span class="text-muted-foreground">n:</span> <span class="font-mono font-medium">{{ korelasiResult.n }}</span></span>
                        </div>
                    </div>
                    <div v-if="korelasiResult.points.length" class="rounded-lg border p-3">
                        <p class="mb-2 text-xs text-muted-foreground">Scatter: Avg Error (X) vs Rejection Rate % (Y)</p>
                        <VueApexCharts type="scatter" :height="280" :options="korelasiChartOptions" :series="korelasiSeries" />
                    </div>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-xs">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">Pencacah</th>
                                    <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                                    <th class="px-3 py-2 text-right font-medium text-muted-foreground">Avg Error</th>
                                    <th class="px-3 py-2 text-right font-medium text-muted-foreground">Rejection Rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="p in korelasiResult.points" :key="p.nama" class="hover:bg-muted/30">
                                    <td class="px-3 py-2">{{ p.nama }}</td>
                                    <td class="px-3 py-2 text-right">{{ p.total.toLocaleString('id') }}</td>
                                    <td class="px-3 py-2 text-right" :class="p.avg_error > 1 ? 'font-medium text-red-600 dark:text-red-400' : ''">{{ p.avg_error }}</td>
                                    <td class="px-3 py-2 text-right">{{ p.rejection_rate }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
