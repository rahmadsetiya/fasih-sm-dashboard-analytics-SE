<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Camera,
    ChevronRight,
    Download,
    ListFilter,
    Layers3,
    LocateFixed,
    MapPin,
    Search,
    UserRoundSearch,
    X,
} from '@lucide/vue';
import { useDark } from '@vueuse/core';
import type {
    ExpressionSpecification,
    GeoJSONSource,
    LngLatBoundsLike,
    Map as MapLibreMap,
    MapGeoJSONFeature,
    Popup as MapLibrePopup,
    StyleSpecification,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';

defineOptions({ inheritAttrs: false });

type Level = 'kec' | 'desa' | 'sls' | 'subsls';
type Role = 'pengawas' | 'pencacah';
type Metric =
    | 'progress'
    | 'submitted'
    | 'approved'
    | 'rejected'
    | 'open'
    | 'assignment'
    | 'priority'
    | 'coverage';

interface BoundaryProperties {
    kdprov: string;
    kdkab: string;
    kdkec: string;
    kddesa: string;
    kdsls: string;
    kdsubsls: string;
    idkec: string;
    iddesa: string;
    idsls: string;
    idsubsls: string;
    nmkec: string;
    nmdesa: string;
    nmsls: string;
    subsls?: string;
    luas?: number;
    periode?: string;
    [key: string]: unknown;
}

interface BoundaryFeature {
    type: 'Feature';
    id: string;
    properties: BoundaryProperties;
    geometry: {
        type: 'Polygon' | 'MultiPolygon';
        coordinates: number[][][] | number[][][][];
    };
}

interface BoundaryCollection {
    type: 'FeatureCollection';
    features: BoundaryFeature[];
}

interface BoundaryLineCollection {
    type: 'FeatureCollection';
    features: Array<{
        type: 'Feature';
        properties: { level: Level };
        geometry: { type: 'MultiLineString'; coordinates: number[][][] };
    }>;
}

interface MetricItem {
    key: string;
    label: string;
    total: number;
    petugas: number;
    progress: number;
    submitted: number;
    approved: number;
    rejected: number;
    open: number;
    priority: number;
    value: number;
    delta: number | null;
    statuses: Record<string, number>;
}

interface QualityReport {
    feature_count: number;
    matched: number;
    coverage_pct: number;
    geojson_only: string[];
    database_only: string[];
    database_sentinels: string[];
    invalid_features: unknown[];
    duplicate_ids: string[];
}

interface MetricsResponse {
    items: Record<string, MetricItem>;
    quality: QualityReport;
}

interface Officer {
    id: string;
    name: string;
    type: Role;
    region_count: number;
}

interface PencacahDetail extends MetricItem {
    id: string;
    name: string;
    pml: Array<{ id: string; name: string }>;
}

interface RegionDetail extends Omit<MetricItem, 'petugas'> {
    level: Level;
    next_level: Level | null;
    pencacah: PencacahDetail[];
}

interface DrillItem {
    level: Level;
    key: string | null;
    label: string;
}

const props = defineProps<{
    snapshots: string[];
    geo_ready: boolean;
    db_ready: boolean;
}>();

const LEVELS: Array<{ value: Level; label: string }> = [
    { value: 'kec', label: 'Kecamatan' },
    { value: 'desa', label: 'Desa' },
    { value: 'sls', label: 'SLS' },
    { value: 'subsls', label: 'Sub-SLS' },
];
const METRICS: Array<{ value: Metric; label: string; suffix: string }> = [
    { value: 'progress', label: '% Submit', suffix: '%' },
    { value: 'approved', label: 'Approved', suffix: '%' },
    { value: 'submitted', label: 'Submitted', suffix: '%' },
    { value: 'rejected', label: 'Rejected', suffix: '%' },
    { value: 'open', label: 'Belum dikerjakan', suffix: '%' },
    { value: 'assignment', label: 'Total assignment', suffix: '' },
    { value: 'priority', label: 'Prioritas', suffix: '' },
    { value: 'coverage', label: 'Coverage data', suffix: '' },
];
const MODAL_STATUSES = [
    { key: 'OPEN', label: 'Open', color: 'bg-stone-500' },
    { key: 'DRAFT', label: 'Draft', color: 'bg-amber-500' },
    {
        key: 'SUBMITTED BY Pencacah',
        label: 'Submitted',
        color: 'bg-sky-500',
    },
    {
        key: 'REJECTED BY Pengawas',
        label: 'Rejected',
        color: 'bg-red-500',
    },
    {
        key: 'APPROVED BY Pengawas',
        label: 'Approved',
        color: 'bg-emerald-500',
    },
    {
        key: 'EDITED BY Pengawas',
        label: 'Edited PML',
        color: 'bg-orange-500',
    },
    {
        key: 'REVOKED BY Pengawas',
        label: 'Revoked PML',
        color: 'bg-red-700',
    },
    {
        key: 'SUBMITTED RESPONDENT',
        label: 'Submitted Responden',
        color: 'bg-violet-500',
    },
    {
        key: 'COMPLETED BY Admin Kabupaten',
        label: 'Completed Admin',
        color: 'bg-teal-500',
    },
    {
        key: 'EDITED BY Admin Kabupaten',
        label: 'Edited Admin',
        color: 'bg-orange-600',
    },
    {
        key: 'REJECTED BY Admin Kabupaten',
        label: 'Rejected Admin',
        color: 'bg-rose-700',
    },
    {
        key: 'REVOKED BY Admin Kabupaten',
        label: 'Revoked Admin',
        color: 'bg-red-900',
    },
];

const mapContainer = ref<HTMLElement | null>(null);
const map = shallowRef<MapLibreMap | null>(null);
const popup = shallowRef<MapLibrePopup | null>(null);
const boundaries = ref<BoundaryCollection | null>(null);
const metricItems = ref<Record<string, MetricItem>>({});
const quality = ref<QualityReport | null>(null);
const selectedSnapshot = ref(props.snapshots[0] ?? '');
const compareSnapshot = ref('');
const role = ref<Role>('pengawas');
const metric = ref<Metric>('progress');
const level = ref<Level>('kec');
const drillPath = ref<DrillItem[]>([
    { level: 'kec', key: null, label: 'Enrekang' },
]);
const search = ref('');
const officerType = ref<Role>('pencacah');
const officerSearch = ref('');
const officers = ref<Officer[]>([]);
const selectedOfficerId = ref('');
const selectedOfficer = ref<Officer | null>(null);
const officerRegionIds = ref<string[]>([]);
const selectedRegion = ref<RegionDetail | null>(null);
const selectedRegionLoading = ref(false);
const regionOfficerSearch = ref('');
const loading = ref(false);
const error = ref('');
const mapLoaded = ref(false);
const isDark = useDark();

const currentParent = computed(() => drillPath.value.at(-1)?.key ?? null);
const currentMetric = computed(
    () => METRICS.find((item) => item.value === metric.value) ?? METRICS[0],
);
const otherSnapshots = computed(() =>
    props.snapshots.filter((snapshot) => snapshot !== selectedSnapshot.value),
);

function statusPercent(count: number, total: number): string {
    return `${total ? Math.round((count / total) * 1000) / 10 : 0}%`;
}
const filteredOfficers = computed(() => {
    const term = officerSearch.value.trim().toLowerCase();

    return officers.value.filter((officer) =>
        officer.name.toLowerCase().includes(term),
    );
});
const filteredRegionOfficers = computed(() => {
    const term = regionOfficerSearch.value.trim().toLowerCase();

    return (selectedRegion.value?.pencacah ?? []).filter((person) =>
        `${person.name} ${person.pml.map((pml) => pml.name).join(' ')}`
            .toLowerCase()
            .includes(term),
    );
});

interface RegionOption {
    key: string;
    label: string;
}

function pathKey(targetLevel: Level): string {
    const childLevel: Record<Level, Level | null> = {
        kec: 'desa',
        desa: 'sls',
        sls: 'subsls',
        subsls: null,
    };
    const next = childLevel[targetLevel];

    return next
        ? (drillPath.value.find((item) => item.level === next)?.key ?? '')
        : (selectedRegion.value?.key ?? '');
}

function regionOptions(
    targetLevel: Level,
    parentLevel?: Level,
    parentKey?: string,
): RegionOption[] {
    if (parentLevel && !parentKey) {
        return [];
    }

    const unique = new Map<string, string>();

    for (const feature of boundaries.value?.features ?? []) {
        if (
            parentLevel &&
            parentKey &&
            featureKey(feature.properties, parentLevel) !== parentKey
        ) {
            continue;
        }

        unique.set(
            featureKey(feature.properties, targetLevel),
            featureLabel(feature.properties, targetLevel),
        );
    }

    return [...unique.entries()]
        .map(([key, label]) => ({ key, label }))
        .sort((a, b) => a.label.localeCompare(b.label, 'id'));
}

const kecamatanOptions = computed(() => regionOptions('kec'));
const desaOptions = computed(() =>
    regionOptions('desa', 'kec', pathKey('kec')),
);
const slsOptions = computed(() =>
    regionOptions('sls', 'desa', pathKey('desa')),
);
const subslsOptions = computed(() =>
    regionOptions('subsls', 'sls', pathKey('sls')),
);

function featureKey(
    properties: BoundaryProperties,
    targetLevel = level.value,
): string {
    return targetLevel === 'kec'
        ? String(properties.idkec)
        : targetLevel === 'desa'
          ? String(properties.iddesa)
          : targetLevel === 'sls'
            ? String(properties.idsls)
            : String(properties.idsubsls);
}

function featureLabel(
    properties: BoundaryProperties,
    targetLevel = level.value,
): string {
    if (targetLevel === 'kec') {
        return String(properties.nmkec);
    }

    if (targetLevel === 'desa') {
        return String(properties.nmdesa);
    }

    if (targetLevel === 'sls') {
        return String(properties.nmsls);
    }

    return String(properties.subsls || properties.nmsls || properties.idsubsls);
}

function belongsToParent(properties: BoundaryProperties): boolean {
    const parent = currentParent.value;

    if (!parent) {
        return true;
    }

    if (level.value === 'desa') {
        return String(properties.idkec) === parent;
    }

    if (level.value === 'sls') {
        return String(properties.iddesa) === parent;
    }

    if (level.value === 'subsls') {
        return String(properties.idsls) === parent;
    }

    return true;
}

const visibleFeatures = computed(() =>
    (boundaries.value?.features ?? []).filter((feature) =>
        belongsToParent(feature.properties),
    ),
);

const regionChoices = computed(() => {
    const unique = new Map<string, string>();

    for (const feature of visibleFeatures.value) {
        unique.set(
            featureKey(feature.properties),
            featureLabel(feature.properties),
        );
    }

    return [...unique.entries()]
        .map(([key, label]) => ({ key, label }))
        .filter((item) =>
            `${item.label} ${item.key}`
                .toLowerCase()
                .includes(search.value.toLowerCase()),
        )
        .sort((a, b) => a.label.localeCompare(b.label, 'id'))
        .slice(0, 8);
});

const priorityRegions = computed(() =>
    Object.values(metricItems.value)
        .sort((a, b) => b.priority - a.priority)
        .slice(0, 8),
);

const metricMax = computed(() => {
    if (!['assignment'].includes(metric.value)) {
        return 100;
    }

    return Math.max(
        1,
        ...Object.values(metricItems.value).map((item) => item.value),
    );
});

const legendStops = computed(() => {
    if (compareSnapshot.value) {
        return [
            { color: '#dc2626', label: '< -10' },
            { color: '#f59e0b', label: '-10' },
            { color: '#e5e7eb', label: '0' },
            { color: '#22c55e', label: '+10' },
            { color: '#15803d', label: '> +10' },
        ];
    }

    const max = metricMax.value;

    return [0, 0.25, 0.5, 0.75, 1].map((ratio, index) => ({
        color: ['#fff7ed', '#fdba74', '#fb923c', '#ea580c', '#9a3412'][index],
        label: Math.round(max * ratio).toLocaleString('id-ID'),
    }));
});

function enhancedGeoJSON(): BoundaryCollection {
    return {
        type: 'FeatureCollection',
        features: visibleFeatures.value.map((feature) => {
            const key = featureKey(feature.properties);
            const item = metricItems.value[key];
            const value = compareSnapshot.value ? item?.delta : item?.value;

            return {
                ...feature,
                properties: {
                    ...feature.properties,
                    map_key: key,
                    map_label: item?.label ?? featureLabel(feature.properties),
                    value: value ?? -1,
                    has_data: Boolean(item),
                    total: item?.total ?? 0,
                    petugas: item?.petugas ?? 0,
                    progress: item?.progress ?? 0,
                    approved: item?.approved ?? 0,
                    rejected: item?.rejected ?? 0,
                    officer_match:
                        !selectedOfficerId.value ||
                        officerRegionIds.value.includes(
                            String(feature.properties.idsubsls),
                        ),
                },
            };
        }),
    };
}

function boundaryLines(): BoundaryLineCollection {
    type Edge = {
        coordinates: number[][];
        kec: string;
        desa: string;
        sls: string;
        subsls: string;
    };
    const edges = new Map<string, Edge[]>();
    const keyForPoint = (point: number[]): string =>
        `${Number(point[0]).toFixed(6)},${Number(point[1]).toFixed(6)}`;

    for (const feature of boundaries.value?.features ?? []) {
        const polygons =
            feature.geometry.type === 'Polygon'
                ? [feature.geometry.coordinates as number[][][]]
                : (feature.geometry.coordinates as number[][][][]);

        for (const polygon of polygons) {
            for (const ring of polygon) {
                for (let index = 1; index < ring.length; index += 1) {
                    const from = ring[index - 1];
                    const to = ring[index];
                    const fromKey = keyForPoint(from);
                    const toKey = keyForPoint(to);
                    const edgeKey =
                        fromKey < toKey
                            ? `${fromKey}|${toKey}`
                            : `${toKey}|${fromKey}`;
                    const matches = edges.get(edgeKey) ?? [];
                    matches.push({
                        coordinates: [from, to],
                        kec: feature.properties.idkec,
                        desa: feature.properties.iddesa,
                        sls: feature.properties.idsls,
                        subsls: feature.properties.idsubsls,
                    });
                    edges.set(edgeKey, matches);
                }
            }
        }
    }

    const lines: Record<Level, number[][][]> = {
        kec: [],
        desa: [],
        sls: [],
        subsls: [],
    };

    for (const matches of edges.values()) {
        const distinct = (key: keyof Omit<Edge, 'coordinates'>): number =>
            new Set(matches.map((edge) => edge[key])).size;
        const boundaryLevel: Level =
            matches.length === 1 || distinct('kec') > 1
                ? 'kec'
                : distinct('desa') > 1
                  ? 'desa'
                  : distinct('sls') > 1
                    ? 'sls'
                    : 'subsls';
        lines[boundaryLevel].push(matches[0].coordinates);
    }

    return {
        type: 'FeatureCollection',
        features: (Object.keys(lines) as Level[]).map((boundaryLevel) => ({
            type: 'Feature',
            properties: { level: boundaryLevel },
            geometry: {
                type: 'MultiLineString',
                coordinates: lines[boundaryLevel],
            },
        })),
    };
}

function fillColorExpression(): ExpressionSpecification {
    if (compareSnapshot.value) {
        return [
            'case',
            ['==', ['get', 'has_data'], false],
            '#3f3f46',
            [
                'interpolate',
                ['linear'],
                ['get', 'value'],
                -20,
                '#b91c1c',
                -5,
                '#f59e0b',
                0,
                '#e5e7eb',
                5,
                '#22c55e',
                20,
                '#15803d',
            ],
        ];
    }

    return [
        'case',
        ['==', ['get', 'has_data'], false],
        '#3f3f46',
        [
            'interpolate',
            ['linear'],
            ['get', 'value'],
            0,
            '#fff7ed',
            metricMax.value * 0.25,
            '#fdba74',
            metricMax.value * 0.5,
            '#fb923c',
            metricMax.value * 0.75,
            '#ea580c',
            metricMax.value,
            '#9a3412',
        ],
    ];
}

async function loadBoundaries(): Promise<void> {
    const response = await fetch('/api/geo/boundaries', {
        headers: { Accept: 'application/geo+json' },
    });

    if (!response.ok) {
        throw new Error(
            (await response.json()).message ?? 'GeoJSON gagal dimuat.',
        );
    }

    boundaries.value = (await response.json()) as BoundaryCollection;
}

async function loadOfficers(): Promise<void> {
    if (!boundaries.value) {
        return;
    }

    const params = new URLSearchParams({ type: officerType.value });

    if (currentParent.value) {
        params.set('scope_id', currentParent.value);
    }

    const response = await fetch(`/api/geo/officers?${params}`);

    if (!response.ok) {
        throw new Error('Daftar petugas gagal dimuat.');
    }

    officers.value = ((await response.json()) as { items: Officer[] }).items;
}

async function selectOfficer(event?: Event): Promise<void> {
    if (event) {
        selectedOfficerId.value = (event.target as HTMLSelectElement).value;
    }

    if (!selectedOfficerId.value) {
        selectedOfficer.value = null;
        officerRegionIds.value = [];
        updateMapSource();
        updateUrl();

        return;
    }

    const response = await fetch(
        `/api/geo/officers/${encodeURIComponent(selectedOfficerId.value)}/regions?type=${officerType.value}`,
    );

    if (!response.ok) {
        throw new Error('Wilayah tugas petugas gagal dimuat.');
    }

    const data = (await response.json()) as Officer & { region_ids: string[] };
    selectedOfficer.value = data;
    officerRegionIds.value = data.region_ids;
    updateMapSource();
    updateUrl();
}

async function changeOfficerType(): Promise<void> {
    selectedOfficerId.value = '';
    selectedOfficer.value = null;
    officerRegionIds.value = [];
    officerSearch.value = '';
    await loadOfficers();
    updateMapSource();
    updateUrl();
}

async function loadMetrics(): Promise<void> {
    if (!selectedSnapshot.value) {
        return;
    }

    loading.value = true;
    error.value = '';
    const params = new URLSearchParams({
        snapshot: selectedSnapshot.value,
        role: role.value,
        level: level.value,
        metric: metric.value,
    });

    if (currentParent.value) {
        params.set('parent_id', currentParent.value);
    }

    if (compareSnapshot.value) {
        params.set('compare_snapshot', compareSnapshot.value);
    }

    try {
        const response = await fetch(`/api/geo/metrics?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Metrik peta gagal dimuat.');
        }

        const data = (await response.json()) as MetricsResponse;
        metricItems.value = data.items;
        quality.value = data.quality;
        updateMapSource();
        updateUrl();
    } catch (caught) {
        error.value =
            caught instanceof Error ? caught.message : 'Peta gagal dimuat.';
    } finally {
        loading.value = false;
    }
}

function updateMapSource(): void {
    if (!map.value || !mapLoaded.value || !boundaries.value) {
        return;
    }

    const source = map.value.getSource('boundaries') as
        | GeoJSONSource
        | undefined;
    source?.setData(enhancedGeoJSON());
    map.value.setPaintProperty(
        'regions-fill',
        'fill-color',
        fillColorExpression(),
    );
    map.value.setPaintProperty('regions-fill', 'fill-opacity', [
        'case',
        ['==', ['get', 'officer_match'], false],
        0.12,
        0.82,
    ]);
    map.value.setPaintProperty('regions-outline', 'line-opacity', [
        'case',
        ['==', ['get', 'officer_match'], false],
        0.12,
        0.7,
    ]);

    if (selectedOfficerId.value) {
        fitFeatures(
            visibleFeatures.value.filter((feature) =>
                officerRegionIds.value.includes(feature.properties.idsubsls),
            ),
        );
    } else {
        fitVisibleFeatures();
    }
}

function fitVisibleFeatures(): void {
    fitFeatures(visibleFeatures.value);
}

function fitFeatures(features: BoundaryFeature[]): void {
    if (!map.value || !features.length) {
        return;
    }

    let minLng = Number.POSITIVE_INFINITY;
    let minLat = Number.POSITIVE_INFINITY;
    let maxLng = Number.NEGATIVE_INFINITY;
    let maxLat = Number.NEGATIVE_INFINITY;
    const visit = (value: unknown): void => {
        if (!Array.isArray(value)) {
            return;
        }

        if (
            value.length >= 2 &&
            typeof value[0] === 'number' &&
            typeof value[1] === 'number'
        ) {
            minLng = Math.min(minLng, value[0]);
            minLat = Math.min(minLat, value[1]);
            maxLng = Math.max(maxLng, value[0]);
            maxLat = Math.max(maxLat, value[1]);

            return;
        }

        value.forEach(visit);
    };

    features.forEach((feature) => visit(feature.geometry.coordinates));

    if (!Number.isFinite(minLng)) {
        return;
    }

    const bounds: LngLatBoundsLike = [
        [minLng, minLat],
        [maxLng, maxLat],
    ];
    map.value.fitBounds(bounds, { padding: 42, duration: 450, maxZoom: 14 });
}

function nextLevel(current: Level): Level | null {
    const index = LEVELS.findIndex((item) => item.value === current);

    return LEVELS[index + 1]?.value ?? null;
}

async function drillInto(key: string, label: string): Promise<void> {
    const next = nextLevel(level.value);

    if (!next) {
        await openRegion(key);

        return;
    }

    drillPath.value.push({ level: next, key, label });
    level.value = next;
    search.value = '';
    await loadMetrics();
}

async function goToCrumb(index: number): Promise<void> {
    drillPath.value = drillPath.value.slice(0, index + 1);
    level.value = drillPath.value[index].level;
    selectedRegion.value = null;
    await loadMetrics();
}

async function goBack(): Promise<void> {
    if (drillPath.value.length <= 1) {
        return;
    }

    await goToCrumb(drillPath.value.length - 2);
}

async function applyRegionFilter(
    targetLevel: Level,
    event: Event,
): Promise<void> {
    const key = (event.target as HTMLSelectElement).value;

    if (!key) {
        const retainedPathIndex: Record<Level, number> = {
            kec: 0,
            desa: 1,
            sls: 2,
            subsls: 3,
        };
        selectedRegion.value = null;
        await goToCrumb(
            Math.min(
                retainedPathIndex[targetLevel],
                drillPath.value.length - 1,
            ),
        );

        return;
    }

    const options: Record<Level, RegionOption[]> = {
        kec: kecamatanOptions.value,
        desa: desaOptions.value,
        sls: slsOptions.value,
        subsls: subslsOptions.value,
    };
    const label = options[targetLevel].find((item) => item.key === key)?.label;

    if (!label) {
        return;
    }

    if (targetLevel === 'subsls') {
        await openRegion(key);

        return;
    }

    const parentPathIndex: Record<Exclude<Level, 'subsls'>, number> = {
        kec: 0,
        desa: 1,
        sls: 2,
    };
    drillPath.value = drillPath.value.slice(
        0,
        parentPathIndex[targetLevel] + 1,
    );
    selectedRegion.value = null;
    await drillInto(key, label);
}

async function resetRegionFilter(): Promise<void> {
    drillPath.value = [{ level: 'kec', key: null, label: 'Enrekang' }];
    level.value = 'kec';
    selectedRegion.value = null;
    search.value = '';
    await loadMetrics();
}

async function openRegion(id: string): Promise<void> {
    selectedRegionLoading.value = true;
    selectedRegion.value = null;
    regionOfficerSearch.value = '';

    try {
        const params = new URLSearchParams({
            snapshot: selectedSnapshot.value,
        });
        const response = await fetch(
            `/api/geo/regions/${level.value}/${encodeURIComponent(id)}?${params}`,
        );

        if (!response.ok) {
            throw new Error('Detail wilayah tidak ditemukan.');
        }

        selectedRegion.value = (await response.json()) as RegionDetail;
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : 'Detail wilayah gagal dimuat.';
    } finally {
        selectedRegionLoading.value = false;
    }
}

function handleMapClick(event: { features?: MapGeoJSONFeature[] }): void {
    const properties = event.features?.[0]?.properties;

    if (!properties?.map_key) {
        return;
    }

    void openRegion(String(properties.map_key));
}

async function drillFromModal(): Promise<void> {
    if (!selectedRegion.value?.next_level) {
        return;
    }

    const { key, label } = selectedRegion.value;
    selectedRegion.value = null;
    await drillInto(key, label);
}

function handleMapMove(event: {
    features?: MapGeoJSONFeature[];
    lngLat: { lng: number; lat: number };
}): void {
    const properties = event.features?.[0]?.properties;

    if (!properties || !popup.value) {
        return;
    }

    const suffix = currentMetric.value.suffix;
    const value = Number(properties.value);
    popup.value
        .setLngLat(event.lngLat)
        .setHTML(
            `<strong>${properties.map_label}</strong><br>${currentMetric.value.label}: ${value < 0 ? 'Tidak ada data' : value.toLocaleString('id-ID') + suffix}<br>Assignment: ${Number(properties.total).toLocaleString('id-ID')} · Petugas: ${properties.petugas}`,
        )
        .addTo(map.value!);
}

function updateUrl(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const params = new URLSearchParams();
    params.set('snapshot', selectedSnapshot.value);
    params.set('role', role.value);
    params.set('metric', metric.value);
    params.set('level', level.value);

    if (currentParent.value) {
        params.set('parent', currentParent.value);
    }

    if (compareSnapshot.value) {
        params.set('compare', compareSnapshot.value);
    }

    params.set('officer_type', officerType.value);

    if (selectedOfficerId.value) {
        params.set('officer_id', selectedOfficerId.value);
    }

    window.history.replaceState(
        {},
        '',
        `${window.location.pathname}?${params}`,
    );
}

function restoreDeepLink(params: URLSearchParams): void {
    const requestedLevel = params.get('level') as Level | null;
    const parent = params.get('parent');
    const validLevel = LEVELS.some((item) => item.value === requestedLevel);

    if (!requestedLevel || !validLevel || requestedLevel === 'kec' || !parent) {
        return;
    }

    const sample = boundaries.value?.features.find((feature) => {
        if (requestedLevel === 'desa') {
            return String(feature.properties.idkec) === parent;
        }

        if (requestedLevel === 'sls') {
            return String(feature.properties.iddesa) === parent;
        }

        return String(feature.properties.idsls) === parent;
    });
    const parentLabel = sample
        ? requestedLevel === 'desa'
            ? sample.properties.nmkec
            : requestedLevel === 'sls'
              ? sample.properties.nmdesa
              : sample.properties.nmsls
        : parent;
    level.value = requestedLevel;
    drillPath.value = [
        { level: 'kec', key: null, label: 'Enrekang' },
        { level: requestedLevel, key: parent, label: String(parentLabel) },
    ];
}

function exportCsv(): void {
    const rows = Object.values(metricItems.value);
    const csv = [
        [
            'kode',
            'nama',
            'nilai',
            'delta',
            'assignment',
            'petugas',
            'progress',
            'approved',
            'rejected',
        ],
        ...rows.map((item) => [
            item.key,
            item.label,
            item.value,
            item.delta ?? '',
            item.total,
            item.petugas,
            item.progress,
            item.approved,
            item.rejected,
        ]),
    ]
        .map((row) =>
            row
                .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
                .join(','),
        )
        .join('\n');
    const url = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `peta-${level.value}-${metric.value}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
}

function exportPng(): void {
    if (!map.value) {
        return;
    }

    const anchor = document.createElement('a');
    anchor.href = map.value.getCanvas().toDataURL('image/png');
    anchor.download = `peta-${level.value}-${metric.value}.png`;
    anchor.click();
}

async function initializeMap(): Promise<void> {
    if (!mapContainer.value) {
        return;
    }

    const maplibregl = await import('maplibre-gl');
    const style: StyleSpecification = {
        version: 8,
        sources: {
            basemap: {
                type: 'raster',
                tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                tileSize: 256,
                attribution: '© OpenStreetMap contributors',
            },
        },
        layers: [
            {
                id: 'background',
                type: 'background',
                paint: {
                    'background-color': isDark.value ? '#11100f' : '#f7f3ed',
                },
            },
            {
                id: 'basemap',
                type: 'raster',
                source: 'basemap',
                paint: {
                    'raster-opacity': isDark.value ? 0.28 : 0.55,
                    'raster-saturation': isDark.value ? -0.75 : -0.25,
                    'raster-brightness-max': isDark.value ? 0.48 : 1,
                },
            },
        ],
    };
    map.value = new maplibregl.Map({
        container: mapContainer.value,
        style,
        center: [119.89, -3.52],
        zoom: 9,
        canvasContextAttributes: { preserveDrawingBuffer: true },
        attributionControl: { compact: true },
    });
    popup.value = new maplibregl.Popup({
        closeButton: false,
        closeOnClick: false,
        offset: 12,
    });
    map.value.addControl(
        new maplibregl.NavigationControl({ showCompass: false }),
        'bottom-right',
    );
    map.value.on('load', () => {
        mapLoaded.value = true;
        map.value?.addSource('boundaries', {
            type: 'geojson',
            data: enhancedGeoJSON(),
            promoteId: 'idsubsls',
        });
        map.value?.addLayer({
            id: 'regions-fill',
            type: 'fill',
            source: 'boundaries',
            paint: {
                'fill-color': fillColorExpression(),
                'fill-opacity': 0.82,
            },
        });
        map.value?.addLayer({
            id: 'regions-outline',
            type: 'line',
            source: 'boundaries',
            paint: {
                'line-color': isDark.value ? '#d6d3d1' : '#7c2d12',
                'line-width': level.value === 'subsls' ? 0.8 : 0.25,
                'line-opacity': 0.55,
            },
        });
        map.value?.addSource('administrative-boundaries', {
            type: 'geojson',
            data: boundaryLines(),
        });
        const boundaryStyles: Array<{
            level: Level;
            width: number;
            opacity: number;
            color: string;
        }> = [
            {
                level: 'subsls',
                width: 0.45,
                opacity: 0.42,
                color: isDark.value ? '#a8a29e' : '#78716c',
            },
            {
                level: 'sls',
                width: 1,
                opacity: 0.68,
                color: isDark.value ? '#e7e5e4' : '#57534e',
            },
            {
                level: 'desa',
                width: 1.8,
                opacity: 0.85,
                color: isDark.value ? '#fde68a' : '#c2410c',
            },
            {
                level: 'kec',
                width: 3,
                opacity: 0.95,
                color: isDark.value ? '#fb923c' : '#7c2d12',
            },
        ];

        for (const boundary of boundaryStyles) {
            map.value?.addLayer({
                id: `boundary-${boundary.level}`,
                type: 'line',
                source: 'administrative-boundaries',
                filter: ['==', ['get', 'level'], boundary.level],
                paint: {
                    'line-color': boundary.color,
                    'line-width': [
                        'interpolate',
                        ['linear'],
                        ['zoom'],
                        8,
                        boundary.width * 0.65,
                        14,
                        boundary.width * 1.35,
                    ],
                    'line-opacity': boundary.opacity,
                },
            });
        }

        map.value?.on('click', 'regions-fill', handleMapClick);
        map.value?.on('mousemove', 'regions-fill', handleMapMove);
        map.value?.on('mouseleave', 'regions-fill', () =>
            popup.value?.remove(),
        );
        fitVisibleFeatures();
    });
}

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    selectedSnapshot.value = params.get('snapshot') || selectedSnapshot.value;
    role.value = (params.get('role') as Role) || role.value;
    metric.value = (params.get('metric') as Metric) || metric.value;
    compareSnapshot.value = params.get('compare') || '';
    officerType.value =
        (params.get('officer_type') as Role) || officerType.value;
    selectedOfficerId.value = params.get('officer_id') || '';

    if (!props.geo_ready || !props.db_ready) {
        return;
    }

    loading.value = true;

    try {
        await loadBoundaries();
        restoreDeepLink(params);
        await nextTick();
        await initializeMap();
        await loadMetrics();
        await loadOfficers();

        if (selectedOfficerId.value) {
            await selectOfficer();
        }
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : 'Peta gagal diinisialisasi.';
    } finally {
        loading.value = false;
    }
});

watch(
    [selectedSnapshot, role, metric, compareSnapshot],
    () => void loadMetrics(),
);
watch([level, currentParent], () => void loadOfficers());
watch(isDark, (dark) => {
    map.value?.setPaintProperty(
        'background',
        'background-color',
        dark ? '#11100f' : '#f7f3ed',
    );
    map.value?.setPaintProperty(
        'regions-outline',
        'line-color',
        dark ? '#d6d3d1' : '#7c2d12',
    );
    map.value?.setPaintProperty(
        'basemap',
        'raster-opacity',
        dark ? 0.28 : 0.55,
    );
    map.value?.setPaintProperty(
        'basemap',
        'raster-saturation',
        dark ? -0.75 : -0.25,
    );
    const boundaryColors: Record<Level, [string, string]> = {
        kec: ['#7c2d12', '#fb923c'],
        desa: ['#c2410c', '#fde68a'],
        sls: ['#57534e', '#e7e5e4'],
        subsls: ['#78716c', '#a8a29e'],
    };

    for (const [boundaryLevel, colors] of Object.entries(boundaryColors)) {
        map.value?.setPaintProperty(
            `boundary-${boundaryLevel}`,
            'line-color',
            dark ? colors[1] : colors[0],
        );
    }
});

onBeforeUnmount(() => {
    popup.value?.remove();
    map.value?.remove();
});
</script>

<template>
    <div>
        <Head title="Peta Wilayah" />
        <div class="flex min-h-0 flex-1 flex-col gap-3 p-4">
            <section
                class="flex flex-col gap-3 rounded-2xl border border-orange-300/30 bg-gradient-to-r from-orange-500/10 via-card to-card p-4 shadow-sm lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <div
                        class="flex items-center gap-2 text-orange-600 dark:text-orange-300"
                    >
                        <MapPin class="size-5" />
                        <span
                            class="text-xs font-bold tracking-[0.18em] uppercase"
                            >Peta Operasional</span
                        >
                    </div>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight">
                        Peta Wilayah FASIH
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Eksplorasi progres hingga Sub-SLS tanpa layanan peta
                        eksternal.
                    </p>
                </div>
                <div v-if="quality" class="flex flex-wrap gap-2 text-xs">
                    <span
                        class="rounded-full bg-emerald-500/10 px-3 py-1.5 font-semibold text-emerald-700 dark:text-emerald-300"
                        >{{ quality.coverage_pct }}% geometry matched</span
                    >
                    <span
                        v-if="quality.database_sentinels.length"
                        class="rounded-full bg-amber-500/10 px-3 py-1.5 font-semibold text-amber-700 dark:text-amber-300"
                        >{{ quality.database_sentinels.length }} data tanpa
                        geometri</span
                    >
                </div>
            </section>

            <section
                class="grid gap-3 rounded-2xl border bg-card p-3 shadow-sm sm:grid-cols-2 xl:grid-cols-6"
            >
                <label class="text-xs font-medium text-muted-foreground"
                    >Snapshot
                    <select
                        v-model="selectedSnapshot"
                        class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                    >
                        <option
                            v-for="item in snapshots"
                            :key="item"
                            :value="item"
                        >
                            {{ new Date(item).toLocaleString('id-ID') }}
                        </option>
                    </select>
                </label>
                <label class="text-xs font-medium text-muted-foreground"
                    >Role
                    <select
                        v-model="role"
                        class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                    >
                        <option value="pengawas">Pengawas</option>
                        <option value="pencacah">Pencacah</option>
                    </select>
                </label>
                <label class="text-xs font-medium text-muted-foreground"
                    >Metrik
                    <select
                        v-model="metric"
                        class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                    >
                        <option
                            v-for="item in METRICS"
                            :key="item.value"
                            :value="item.value"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <label class="text-xs font-medium text-muted-foreground"
                    >Bandingkan snapshot
                    <select
                        v-model="compareSnapshot"
                        class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                    >
                        <option value="">Tidak dibandingkan</option>
                        <option
                            v-for="item in otherSnapshots"
                            :key="item"
                            :value="item"
                        >
                            {{ new Date(item).toLocaleString('id-ID') }}
                        </option>
                    </select>
                </label>
                <div class="flex items-end gap-2 sm:col-span-2">
                    <button
                        class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg border bg-background px-3 text-xs font-semibold hover:bg-muted"
                        @click="exportCsv"
                    >
                        <Download class="size-4" />CSV
                    </button>
                    <button
                        class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg border bg-background px-3 text-xs font-semibold hover:bg-muted"
                        @click="exportPng"
                    >
                        <Camera class="size-4" />PNG
                    </button>
                </div>
            </section>

            <section
                class="rounded-2xl border border-orange-300/25 bg-card p-3 shadow-sm"
            >
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span
                            class="grid size-8 place-items-center rounded-lg bg-orange-500/10 text-orange-600 dark:text-orange-300"
                        >
                            <ListFilter class="size-4" />
                        </span>
                        <div>
                            <h2 class="text-sm font-semibold">
                                Filter wilayah
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Pilih wilayah secara bertingkat.
                            </p>
                        </div>
                    </div>
                    <button
                        class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold hover:bg-muted"
                        @click="resetRegionFilter"
                    >
                        Semua wilayah
                    </button>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-xs font-medium text-muted-foreground">
                        Kecamatan
                        <select
                            :value="pathKey('kec')"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                            @change="applyRegionFilter('kec', $event)"
                        >
                            <option value="">Semua kecamatan</option>
                            <option
                                v-for="item in kecamatanOptions"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Desa
                        <select
                            :disabled="!pathKey('kec')"
                            :value="pathKey('desa')"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            @change="applyRegionFilter('desa', $event)"
                        >
                            <option value="">Semua desa</option>
                            <option
                                v-for="item in desaOptions"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        SLS
                        <select
                            :disabled="!pathKey('desa')"
                            :value="pathKey('sls')"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            @change="applyRegionFilter('sls', $event)"
                        >
                            <option value="">Semua SLS</option>
                            <option
                                v-for="item in slsOptions"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Sub-SLS
                        <select
                            :disabled="!pathKey('sls')"
                            :value="pathKey('subsls')"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            @change="applyRegionFilter('subsls', $event)"
                        >
                            <option value="">Semua Sub-SLS</option>
                            <option
                                v-for="item in subslsOptions"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                </div>
            </section>

            <section
                class="rounded-2xl border border-emerald-300/25 bg-card p-3 shadow-sm"
            >
                <div class="mb-3 flex items-center gap-2">
                    <span
                        class="grid size-8 place-items-center rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                    >
                        <UserRoundSearch class="size-4" />
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold">Filter petugas</h2>
                        <p class="text-xs text-muted-foreground">
                            Sorot seluruh wilayah tugas PPL atau PML.
                        </p>
                    </div>
                </div>
                <div class="grid gap-2 sm:grid-cols-[10rem_1fr_1fr_auto]">
                    <label class="text-xs font-medium text-muted-foreground">
                        Jenis petugas
                        <select
                            v-model="officerType"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                            @change="changeOfficerType"
                        >
                            <option value="pencacah">Pencacah / PPL</option>
                            <option value="pengawas">Pengawas / PML</option>
                        </select>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Cari nama
                        <input
                            v-model="officerSearch"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-3 text-sm text-foreground"
                            placeholder="Ketik nama petugas..."
                        />
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Petugas
                        <select
                            :value="selectedOfficerId"
                            class="mt-1 h-9 w-full rounded-lg border bg-background px-2 text-sm text-foreground"
                            @change="selectOfficer"
                        >
                            <option value="">Semua petugas</option>
                            <option
                                v-for="officer in filteredOfficers"
                                :key="officer.id"
                                :value="officer.id"
                            >
                                {{ officer.name }} ({{ officer.region_count }})
                            </option>
                        </select>
                    </label>
                    <button
                        class="mt-auto h-9 rounded-lg border px-3 text-xs font-semibold hover:bg-muted"
                        @click="
                            selectedOfficerId = '';
                            selectOfficer();
                        "
                    >
                        Reset
                    </button>
                </div>
                <div
                    v-if="selectedOfficer"
                    class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-emerald-500/10 px-3 py-2 text-xs"
                >
                    <span class="font-semibold">{{
                        selectedOfficer.name
                    }}</span>
                    <span class="text-muted-foreground">
                        {{
                            selectedOfficer.type === 'pencacah' ? 'PPL' : 'PML'
                        }}
                        · {{ selectedOfficer.region_count }} Sub-SLS tugas
                    </span>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <button
                    v-if="drillPath.length > 1"
                    class="mr-1 inline-flex items-center gap-1 rounded-full border px-2.5 py-1.5 hover:bg-muted"
                    @click="goBack"
                >
                    <ArrowLeft class="size-3.5" />Kembali
                </button>
                <template
                    v-for="(crumb, index) in drillPath"
                    :key="`${crumb.level}-${crumb.key}`"
                >
                    <ChevronRight
                        v-if="index"
                        class="size-3.5 text-muted-foreground"
                    />
                    <button
                        class="rounded-full px-2.5 py-1.5 font-semibold hover:bg-muted"
                        :class="
                            index === drillPath.length - 1
                                ? 'bg-orange-500/10 text-orange-700 dark:text-orange-300'
                                : 'text-muted-foreground'
                        "
                        @click="goToCrumb(index)"
                    >
                        {{ crumb.label }}
                    </button>
                </template>
            </div>

            <div
                v-if="!geo_ready || !db_ready"
                class="grid min-h-96 place-items-center rounded-2xl border border-dashed bg-card p-8 text-center"
            >
                <div>
                    <Layers3 class="mx-auto size-10 text-muted-foreground" />
                    <h2 class="mt-3 font-semibold">Data peta belum tersedia</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Pastikan GeoJSON dan fasih.db tersedia di storage/app.
                    </p>
                </div>
            </div>
            <div
                v-else
                class="relative grid min-h-[38rem] flex-1 overflow-hidden rounded-2xl border bg-card shadow-sm lg:grid-cols-[1fr_18rem]"
            >
                <div class="relative min-h-[38rem]">
                    <div class="absolute inset-0">
                        <div ref="mapContainer" class="size-full" />
                    </div>
                    <div
                        class="absolute top-3 left-3 z-10 w-[min(20rem,calc(100%-1.5rem))]"
                    >
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            /><input
                                v-model="search"
                                class="h-10 w-full rounded-xl border bg-background/95 pr-3 pl-9 text-sm shadow-lg backdrop-blur outline-none focus:ring-2 focus:ring-orange-400"
                                :placeholder="`Cari ${LEVELS.find((item) => item.value === level)?.label}...`"
                            />
                        </div>
                        <div
                            v-if="search"
                            class="mt-1 overflow-hidden rounded-xl border bg-background/95 shadow-xl backdrop-blur"
                        >
                            <button
                                v-for="item in regionChoices"
                                :key="item.key"
                                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                                @click="drillInto(item.key, item.label)"
                            >
                                <span>{{ item.label }}</span
                                ><span
                                    class="text-[10px] text-muted-foreground"
                                    >{{ item.key }}</span
                                >
                            </button>
                            <p
                                v-if="!regionChoices.length"
                                class="px-3 py-3 text-xs text-muted-foreground"
                            >
                                Wilayah tidak ditemukan.
                            </p>
                        </div>
                    </div>
                    <button
                        class="absolute right-3 bottom-20 z-10 flex size-9 items-center justify-center rounded-lg border bg-background shadow-lg hover:bg-muted"
                        title="Reset viewport"
                        @click="fitVisibleFeatures"
                    >
                        <LocateFixed class="size-4" />
                    </button>
                    <div
                        class="absolute bottom-3 left-3 z-10 rounded-xl border bg-background/90 p-3 shadow-lg backdrop-blur"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide uppercase"
                        >
                            {{
                                compareSnapshot
                                    ? `Delta ${currentMetric.label}`
                                    : currentMetric.label
                            }}
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <div
                                v-for="stop in legendStops"
                                :key="stop.label"
                                class="text-center"
                            >
                                <span
                                    class="block h-2.5 w-10 rounded-sm"
                                    :style="{ backgroundColor: stop.color }"
                                /><span
                                    class="mt-1 block text-[9px] text-muted-foreground"
                                    >{{ stop.label }}</span
                                >
                            </div>
                        </div>
                        <div class="mt-2 border-t pt-2">
                            <p
                                class="text-[9px] font-bold tracking-wide text-muted-foreground uppercase"
                            >
                                Batas wilayah
                            </p>
                            <div
                                class="mt-1.5 grid grid-cols-2 gap-x-3 gap-y-1 text-[9px] text-muted-foreground"
                            >
                                <span class="flex items-center gap-1.5"
                                    ><i
                                        class="block w-6 border-t-[3px] border-orange-700 dark:border-orange-400"
                                    />Kecamatan</span
                                >
                                <span class="flex items-center gap-1.5"
                                    ><i
                                        class="block w-6 border-t-2 border-orange-600 dark:border-amber-200"
                                    />Desa</span
                                >
                                <span class="flex items-center gap-1.5"
                                    ><i
                                        class="block w-6 border-t border-stone-600 dark:border-stone-200"
                                    />SLS</span
                                >
                                <span class="flex items-center gap-1.5"
                                    ><i
                                        class="block w-6 border-t border-dashed border-stone-400"
                                    />Sub-SLS</span
                                >
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="loading"
                        class="absolute inset-0 z-20 grid place-items-center bg-background/40 backdrop-blur-sm"
                    >
                        <span
                            class="rounded-full bg-background px-4 py-2 text-sm font-semibold shadow-lg"
                            >Memuat peta...</span
                        >
                    </div>
                    <div
                        v-if="error"
                        class="absolute top-16 right-3 left-3 z-30 flex items-center justify-between rounded-xl border border-red-400/40 bg-red-500/10 p-3 text-sm text-red-700 backdrop-blur dark:text-red-300"
                    >
                        <span>{{ error }}</span
                        ><button @click="error = ''">
                            <X class="size-4" />
                        </button>
                    </div>
                </div>

                <aside
                    class="border-t bg-background/70 p-4 lg:border-t-0 lg:border-l"
                >
                    <h2 class="text-sm font-bold">Wilayah prioritas</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Submit rendah, open dan rejection tinggi.
                    </p>
                    <div class="mt-3 space-y-2">
                        <button
                            v-for="(item, index) in priorityRegions"
                            :key="item.key"
                            class="flex w-full items-center gap-2 rounded-xl border bg-card p-2.5 text-left hover:border-orange-400/50"
                            @click="drillInto(item.key, item.label)"
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-[10px] font-bold text-orange-700"
                                >{{ index + 1 }}</span
                            ><span class="min-w-0 flex-1"
                                ><span
                                    class="block truncate text-xs font-semibold"
                                    >{{ item.label }}</span
                                ><span
                                    class="block text-[10px] text-muted-foreground"
                                    >Submit {{ item.progress }}% · Rej
                                    {{ item.rejected }}%</span
                                ></span
                            ><span class="text-xs font-bold text-orange-600">{{
                                item.priority
                            }}</span>
                        </button>
                    </div>
                    <div
                        v-if="quality"
                        class="mt-5 rounded-xl border bg-card p-3"
                    >
                        <p class="text-xs font-bold">Kualitas geometri</p>
                        <dl class="mt-2 grid grid-cols-2 gap-2 text-[10px]">
                            <div>
                                <dt class="text-muted-foreground">Matched</dt>
                                <dd class="font-bold">
                                    {{ quality.matched }}/{{
                                        quality.feature_count
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Coverage</dt>
                                <dd class="font-bold">
                                    {{ quality.coverage_pct }}%
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">DB only</dt>
                                <dd class="font-bold">
                                    {{ quality.database_only.length }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Sentinel</dt>
                                <dd class="font-bold">
                                    {{ quality.database_sentinels.length }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </aside>

                <div
                    v-if="selectedRegion || selectedRegionLoading"
                    class="fixed inset-0 z-50 flex items-end justify-center bg-black/55 p-0 backdrop-blur-sm md:items-center md:p-6"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Detail wilayah"
                    @click.self="selectedRegion = null"
                >
                    <section
                        class="max-h-[92vh] w-full overflow-y-auto rounded-t-3xl border bg-background shadow-2xl md:max-w-4xl md:rounded-3xl"
                    >
                        <header
                            class="sticky top-0 z-10 flex items-start justify-between border-b bg-background/95 p-4 backdrop-blur md:p-5"
                        >
                            <div>
                                <p
                                    class="text-[10px] font-bold tracking-[0.16em] text-orange-600 uppercase"
                                >
                                    Detail
                                    {{
                                        LEVELS.find(
                                            (item) =>
                                                item.value ===
                                                (selectedRegion?.level ??
                                                    level),
                                        )?.label
                                    }}
                                </p>
                                <h2 class="mt-1 text-xl font-bold">
                                    {{
                                        selectedRegion?.label ??
                                        'Memuat data...'
                                    }}
                                </h2>
                                <p
                                    v-if="selectedRegion"
                                    class="mt-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ selectedRegion.key }}
                                </p>
                            </div>
                            <button
                                aria-label="Tutup detail wilayah"
                                class="rounded-xl border p-2 hover:bg-muted"
                                @click="selectedRegion = null"
                            >
                                <X class="size-4" />
                            </button>
                        </header>

                        <div
                            v-if="selectedRegionLoading"
                            class="grid min-h-72 place-items-center text-sm text-muted-foreground"
                        >
                            Memuat detail wilayah...
                        </div>
                        <div
                            v-else-if="selectedRegion"
                            class="space-y-5 p-4 md:p-5"
                        >
                            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                                <div class="rounded-2xl bg-orange-500/10 p-3">
                                    <p class="text-xs text-muted-foreground">
                                        % Submit
                                    </p>
                                    <p class="mt-1 text-2xl font-bold">
                                        {{ selectedRegion.progress }}%
                                    </p>
                                </div>
                                <div class="rounded-2xl border bg-card p-3">
                                    <p class="text-xs text-muted-foreground">
                                        Assignment
                                    </p>
                                    <p class="mt-1 text-2xl font-bold">
                                        {{
                                            selectedRegion.total.toLocaleString(
                                                'id-ID',
                                            )
                                        }}
                                    </p>
                                </div>
                                <div class="rounded-2xl border bg-card p-3">
                                    <p class="text-xs text-muted-foreground">
                                        Pencacah / PPL
                                    </p>
                                    <p class="mt-1 text-2xl font-bold">
                                        {{ selectedRegion.pencacah.length }}
                                    </p>
                                </div>
                                <button
                                    v-if="selectedRegion.next_level"
                                    class="rounded-2xl bg-orange-600 p-3 text-left text-white hover:bg-orange-700"
                                    @click="drillFromModal"
                                >
                                    <span class="text-xs opacity-80"
                                        >Navigasi peta</span
                                    >
                                    <span class="mt-1 block font-bold"
                                        >Lihat wilayah di bawahnya</span
                                    >
                                </button>
                            </div>

                            <div>
                                <h3 class="text-sm font-bold">
                                    Komposisi submit
                                </h3>
                                <div
                                    class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5"
                                >
                                    <div
                                        v-for="status in MODAL_STATUSES"
                                        :key="status.key"
                                        class="rounded-xl border bg-card p-3"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="size-2 rounded-full"
                                                :class="status.color"
                                            />
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >{{ status.label }}</span
                                            >
                                        </div>
                                        <p class="mt-2 text-lg font-bold">
                                            {{
                                                (
                                                    selectedRegion.statuses[
                                                        status.key
                                                    ] ?? 0
                                                ).toLocaleString('id-ID')
                                            }}
                                        </p>
                                        <p
                                            class="text-[10px] text-muted-foreground"
                                        >
                                            {{
                                                statusPercent(
                                                    selectedRegion.statuses[
                                                        status.key
                                                    ] ?? 0,
                                                    selectedRegion.total,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div
                                    class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
                                >
                                    <div>
                                        <h3 class="text-sm font-bold">
                                            Pencacah dan pengawas
                                        </h3>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Submit per PPL beserta PML
                                            penanggung jawab.
                                        </p>
                                    </div>
                                    <input
                                        v-model="regionOfficerSearch"
                                        class="h-9 rounded-lg border bg-background px-3 text-sm sm:w-64"
                                        placeholder="Cari PPL atau PML..."
                                    />
                                </div>
                                <div class="mt-3 grid gap-2 md:grid-cols-2">
                                    <article
                                        v-for="person in filteredRegionOfficers"
                                        :key="person.id"
                                        class="rounded-2xl border bg-card p-3"
                                    >
                                        <div
                                            class="flex items-start justify-between gap-3"
                                        >
                                            <div>
                                                <p class="font-semibold">
                                                    {{ person.name }}
                                                </p>
                                                <p
                                                    class="mt-0.5 text-[11px] text-muted-foreground"
                                                >
                                                    PML:
                                                    {{
                                                        person.pml
                                                            .map(
                                                                (pml) =>
                                                                    pml.name,
                                                            )
                                                            .join(', ') ||
                                                        'Belum tersedia'
                                                    }}
                                                </p>
                                            </div>
                                            <span
                                                class="rounded-full bg-orange-500/10 px-2 py-1 text-xs font-bold text-orange-700 dark:text-orange-300"
                                                >{{ person.progress }}%</span
                                            >
                                        </div>
                                        <div
                                            class="mt-3 grid grid-cols-5 gap-1 text-center"
                                        >
                                            <div
                                                v-for="status in MODAL_STATUSES"
                                                :key="status.key"
                                                class="rounded-lg bg-muted/60 px-1 py-1.5"
                                            >
                                                <p
                                                    class="text-[9px] text-muted-foreground"
                                                >
                                                    {{ status.label }}
                                                </p>
                                                <p class="text-xs font-bold">
                                                    {{
                                                        person.statuses[
                                                            status.key
                                                        ] ?? 0
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </article>
                                </div>
                                <p
                                    v-if="!filteredRegionOfficers.length"
                                    class="rounded-xl border border-dashed p-5 text-center text-sm text-muted-foreground"
                                >
                                    Tidak ada pencacah yang sesuai.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.maplibregl-popup-content {
    border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
    border-radius: 12px;
    background: hsl(var(--background));
    color: hsl(var(--foreground));
    font-size: 12px;
    line-height: 1.5;
    box-shadow: 0 12px 30px rgb(0 0 0 / 18%);
}

.maplibregl-popup-tip {
    display: none;
}
</style>
