<script setup lang="ts">
import { MapPin } from '@lucide/vue';
import Dialog from 'primevue/dialog';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const open = ref(false);
const csvText = ref('');
const loading = ref(false);
const count = ref<number | null>(null);

async function fetchCount() {
    try {
        const res = await fetch('/api/region-names', {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        count.value = data.length;
    } catch {
        count.value = null;
    }
}

function openDialog() {
    open.value = true;
    fetchCount();
}

function getCsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

async function importCsv() {
    if (!csvText.value.trim()) {
        toast.error('Paste data CSV terlebih dahulu.');

        return;
    }

    loading.value = true;

    try {
        const res = await fetch('/api/region-names/import', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ csv: csvText.value }),
        });
        const data = await res.json();

        if (res.ok) {
            toast.success(data.message);
            csvText.value = '';
            fetchCount();
        } else {
            toast.error(data.message ?? 'Gagal import.');
        }
    } finally {
        loading.value = false;
    }
}

async function clearAll() {
    if (!confirm('Hapus semua nama wilayah yang tersimpan?')) {
return;
}

    const res = await fetch('/api/region-names/all', {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const data = await res.json();
    toast.success(data.message);
    count.value = 0;
}
</script>

<template>
    <button
        class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-neutral-600 transition-colors hover:bg-sidebar-accent/60 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
        @click="openDialog"
    >
        <MapPin class="size-4 shrink-0" />
        <span>Nama Wilayah</span>
    </button>

    <Dialog
        v-model:visible="open"
        header="Import Nama Wilayah"
        :modal="true"
        :style="{ width: '32rem' }"
        :draggable="false"
    >
        <p class="mb-3 text-sm text-muted-foreground">
            Paste data CSV dengan format
            <code class="rounded bg-muted px-1 text-xs">kode,nama</code>
            (satu baris per wilayah, tanpa header).
            <span
                v-if="count !== null"
                class="ml-1 font-medium text-foreground"
            >
                {{ count }} nama tersimpan.
            </span>
        </p>

        <div class="mb-3 space-y-1.5">
            <p class="text-xs text-muted-foreground">Contoh:</p>
            <pre
                class="rounded-md bg-muted px-3 py-2 text-xs text-foreground"
            >7316010,Maiwa
7316020,Enrekang
7316030,Curio</pre>
        </div>

        <textarea
            v-model="csvText"
            rows="8"
            placeholder="7316010,Maiwa&#10;7316020,Enrekang&#10;..."
            class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 font-mono text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-ring focus:outline-none"
            aria-label="Data CSV nama wilayah"
        />

        <div class="mt-4 flex items-center justify-between gap-3">
            <button
                v-if="count && count > 0"
                type="button"
                class="rounded text-xs text-destructive underline-offset-4 hover:underline focus:outline-none"
                @click="clearAll"
            >
                Hapus semua ({{ count }})
            </button>
            <div class="flex-1" />
            <button
                type="button"
                :disabled="loading"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90 focus:outline-none disabled:opacity-50"
                @click="importCsv"
            >
                {{ loading ? 'Menyimpan...' : 'Import' }}
            </button>
        </div>
    </Dialog>
</template>
