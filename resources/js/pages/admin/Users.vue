<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    created_at: string;
}

const props = defineProps<{ users: UserRow[] }>();

// ── form dialog ────────────────────────────────────────────────────────────
const dialogOpen = ref(false);
const editUser = ref<UserRow | null>(null);
const form = ref({ name: '', email: '', password: '', is_admin: false });
const saving = ref(false);
const error = ref('');

function openCreate() {
    editUser.value = null;
    form.value = { name: '', email: '', password: '', is_admin: false };
    error.value = '';
    dialogOpen.value = true;
}

function openEdit(user: UserRow) {
    editUser.value = user;
    form.value = { name: user.name, email: user.email, password: '', is_admin: user.is_admin };
    error.value = '';
    dialogOpen.value = true;
}

async function submit() {
    saving.value = true;
    error.value = '';
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf,
    };
    try {
        const body = { ...form.value };
        if (!body.password) delete (body as any).password;

        const res = await fetch(
            editUser.value ? `/admin/users/${editUser.value.id}` : '/admin/users',
            { method: editUser.value ? 'PUT' : 'POST', headers, body: JSON.stringify(body) },
        );
        const data = await res.json();
        if (!res.ok) { error.value = data.message ?? 'Terjadi kesalahan.'; return; }
        dialogOpen.value = false;
        router.reload({ only: ['users'] });
    } finally {
        saving.value = false;
    }
}

// ── delete ─────────────────────────────────────────────────────────────────
const deleteTarget = ref<UserRow | null>(null);
const deleting = ref(false);

async function confirmDelete() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    try {
        const res = await fetch(`/admin/users/${deleteTarget.value.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        });
        if (res.ok) { deleteTarget.value = null; router.reload({ only: ['users'] }); }
    } finally {
        deleting.value = false;
    }
}

function fmtDate(s: string) {
    return new Date(s).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head title="Admin — Manajemen User" />

    <div class="mx-auto max-w-4xl p-6">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold">Manajemen User</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ props.users.length }} user terdaftar</p>
            </div>
            <button
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/80"
                @click="openCreate"
            >
                + Tambah User
            </button>
        </div>

        <!-- User table -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold">Nama</th>
                        <th class="px-4 py-2.5 font-semibold">Email</th>
                        <th class="px-4 py-2.5 font-semibold">Role</th>
                        <th class="px-4 py-2.5 font-semibold">Dibuat</th>
                        <th class="px-4 py-2.5 font-semibold"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in props.users"
                        :key="user.id"
                        class="border-b border-sidebar-border/30 last:border-0 hover:bg-muted/20"
                    >
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-medium',
                                    user.is_admin
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                {{ user.is_admin ? 'Admin' : 'User' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ fmtDate(user.created_at) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button
                                    class="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                                    @click="openEdit(user)"
                                >Edit</button>
                                <button
                                    class="rounded px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20"
                                    @click="deleteTarget = user"
                                >Hapus</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit dialog -->
    <Teleport to="body">
        <div
            v-if="dialogOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="dialogOpen = false"
        >
            <div class="w-full max-w-md rounded-xl border border-sidebar-border bg-card p-6 shadow-xl">
                <h2 class="mb-4 text-base font-semibold">
                    {{ editUser ? 'Edit User' : 'Tambah User Baru' }}
                </h2>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Nama</label>
                        <input v-model="form.name" type="text" required
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring focus:outline-none" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Email</label>
                        <input v-model="form.email" type="email" required
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring focus:outline-none" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            Password
                            <span v-if="editUser" class="font-normal text-muted-foreground">(kosongkan jika tidak ingin mengubah)</span>
                        </label>
                        <input v-model="form.password" type="password" :required="!editUser"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring focus:outline-none" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_admin" type="checkbox" class="rounded" />
                        <span>Berikan akses Admin</span>
                    </label>
                    <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/30">{{ error }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button"
                            class="rounded-md border border-input px-4 py-2 text-sm hover:bg-muted"
                            @click="dialogOpen = false">Batal</button>
                        <button type="submit" :disabled="saving"
                            class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/80 disabled:opacity-50">
                            {{ saving ? 'Menyimpan…' : 'Simpan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>

    <!-- Delete confirmation -->
    <Teleport to="body">
        <div
            v-if="deleteTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="deleteTarget = null"
        >
            <div class="w-full max-w-sm rounded-xl border border-sidebar-border bg-card p-6 shadow-xl">
                <h2 class="mb-2 text-base font-semibold">Hapus User?</h2>
                <p class="mb-4 text-sm text-muted-foreground">
                    User <strong>{{ deleteTarget.name }}</strong> ({{ deleteTarget.email }}) akan dihapus permanen.
                </p>
                <div class="flex justify-end gap-2">
                    <button class="rounded-md border border-input px-4 py-2 text-sm hover:bg-muted" @click="deleteTarget = null">Batal</button>
                    <button
                        class="rounded-md bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50"
                        :disabled="deleting" @click="confirmDelete">
                        {{ deleting ? 'Menghapus…' : 'Hapus' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
