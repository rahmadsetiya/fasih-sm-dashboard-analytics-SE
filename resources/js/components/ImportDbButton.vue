<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Upload, Database, CheckCircle2, AlertCircle } from '@lucide/vue';
import Dialog from 'primevue/dialog';
import { ref, onMounted } from 'vue';
import { toast } from 'vue-sonner';

interface DbStatus {
    exists: boolean;
    size_mb?: number;
    modified_at?: string;
    path?: string;
}

const open = ref(false);
const status = ref<DbStatus>({ exists: false });
const uploading = ref(false);
const progress = ref(0);
const dragover = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

async function fetchStatus() {
    try {
        const res = await fetch('/api/db-status', {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        status.value = await res.json();
    } catch {
        // silently ignore
    }
}

onMounted(fetchStatus);

function onDragover(e: DragEvent) {
    e.preventDefault();
    dragover.value = true;
}

function onDragleave() {
    dragover.value = false;
}

function onDrop(e: DragEvent) {
    e.preventDefault();
    dragover.value = false;
    const file = e.dataTransfer?.files?.[0];

    if (file) {
        handleFile(file);
    }
}

function onFileChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];

    if (file) {
        handleFile(file);
    }
}

function handleFile(file: File) {
    if (!file.name.endsWith('.db') && !file.name.endsWith('.sqlite')) {
        toast.error('Pilih file dengan ekstensi .db atau .sqlite');

        return;
    }

    uploadFile(file);
}

function uploadFile(file: File) {
    uploading.value = true;
    progress.value = 0;

    const formData = new FormData();
    formData.append('db', file);

    const xsrfCookie = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const csrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie[1]) : '';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/import-db');
    xhr.setRequestHeader('X-XSRF-TOKEN', csrfToken);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            progress.value = Math.round((e.loaded / e.total) * 100);
        }
    };

    xhr.onload = () => {
        uploading.value = false;

        let data: { message?: string; size_mb?: number; modified_at?: string };

        try {
            data = JSON.parse(xhr.responseText);
        } catch {
            const preview = xhr.responseText
                .slice(0, 120)
                .replace(/<[^>]+>/g, ' ')
                .trim();
            toast.error(`HTTP ${xhr.status}: ${preview || 'No response body'}`);

            if (fileInput.value) {
                fileInput.value.value = '';
            }

            return;
        }

        if (xhr.status === 200) {
            status.value = {
                exists: true,
                size_mb: data.size_mb,
                modified_at: data.modified_at,
            };
            toast.success(data.message ?? 'Database berhasil diimport.');
            open.value = false;
            router.reload({ only: ['snapshots', 'db_ready'] });
        } else {
            toast.error(data.message ?? 'Gagal mengimport database.');
        }

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    };

    xhr.onerror = () => {
        uploading.value = false;
        toast.error('Terjadi kesalahan jaringan.');
    };

    xhr.send(formData);
}

function fmtDate(s?: string) {
    if (!s) {
        return '-';
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
    <button
        class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-neutral-600 transition-colors hover:bg-sidebar-accent/60 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
        @click="open = true"
    >
        <Database class="size-4 shrink-0" />
        <span>Import Database</span>
    </button>

    <Dialog
        v-model:visible="open"
        header="Import Database FASIH"
        :modal="true"
        :style="{ width: '28rem' }"
        :draggable="false"
    >
        <div
            :class="[
                'mb-4 flex items-start gap-3 rounded-lg border p-3 text-sm',
                status.exists
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300',
            ]"
        >
            <component
                :is="status.exists ? CheckCircle2 : AlertCircle"
                class="mt-0.5 size-4 shrink-0"
            />
            <div class="space-y-0.5">
                <p class="font-medium">
                    {{
                        status.exists ? 'Database aktif' : 'Belum ada database'
                    }}
                </p>
                <p v-if="status.exists" class="text-xs opacity-80">
                    {{ status.size_mb }} MB &mdash; diperbarui
                    {{ fmtDate(status.modified_at) }}
                </p>
                <p v-else class="text-xs opacity-80">
                    Import file fasih.db untuk mulai menggunakan dashboard.
                </p>
            </div>
        </div>

        <div
            :class="[
                'relative flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 py-10 text-center transition-colors',
                dragover
                    ? 'border-primary bg-primary/5'
                    : 'border-muted-foreground/25 hover:border-muted-foreground/50',
                uploading ? 'pointer-events-none opacity-60' : 'cursor-pointer',
            ]"
            @dragover="onDragover"
            @dragleave="onDragleave"
            @drop="onDrop"
            @click="fileInput?.click()"
        >
            <Upload class="size-8 text-muted-foreground" />
            <div>
                <p class="text-sm font-medium">Drag &amp; drop file di sini</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    atau klik untuk memilih file (.db, .sqlite)
                </p>
            </div>
            <input
                ref="fileInput"
                type="file"
                accept=".db,.sqlite"
                class="sr-only"
                @change="onFileChange"
            />
        </div>

        <div v-if="uploading" class="mt-4 space-y-1.5">
            <div class="flex justify-between text-xs text-muted-foreground">
                <span>Mengupload...</span>
                <span>{{ progress }}%</span>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary transition-all duration-300"
                    :style="{ width: progress + '%' }"
                />
            </div>
        </div>

        <p class="mt-4 text-center text-xs text-muted-foreground">
            Upload file
            <code class="rounded bg-muted px-1 py-0.5">fasih.db</code>
            untuk memperbarui data dashboard.
        </p>
    </Dialog>
</template>
