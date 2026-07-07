<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, GitCommitHorizontal, Sparkles } from '@lucide/vue';
import type { ReleaseEntry } from '@/types';

const props = defineProps<{
    currentVersion: string;
    releases: ReleaseEntry[];
}>();

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });
}

const latestRelease = props.releases[0] ?? null;
</script>

<template>
    <Head title="Changelog" />

    <div class="space-y-6 p-4 md:p-6">
        <section
            class="overflow-hidden rounded-3xl border border-orange-200/70 bg-gradient-to-br from-orange-50 via-amber-50 to-white shadow-sm"
        >
            <div class="space-y-4 p-6 md:p-8">
                <div
                    class="inline-flex w-fit items-center gap-2 rounded-full bg-orange-100 px-3 py-1 text-xs font-semibold tracking-[0.24em] text-orange-700 uppercase"
                >
                    <Sparkles class="size-3.5" />
                    Release Notes
                </div>

                <div class="space-y-2">
                    <h1
                        class="text-2xl font-semibold text-slate-900 md:text-3xl"
                    >
                        Versi {{ currentVersion }}
                    </h1>
                    <p
                        class="max-w-3xl text-sm leading-6 text-slate-600 md:text-base"
                    >
                        Halaman ini merangkum perubahan yang sudah dirilis agar
                        user dapat mengetahui fitur baru, perbaikan, dan modul
                        yang sedang dinonaktifkan.
                    </p>
                </div>

                <div
                    v-if="latestRelease"
                    class="grid gap-3 text-sm text-slate-700 md:grid-cols-2"
                >
                    <div
                        class="flex items-center gap-2 rounded-2xl bg-white/80 px-4 py-3"
                    >
                        <GitCommitHorizontal class="size-4 text-orange-600" />
                        <span>Rilis aktif: {{ latestRelease.title }}</span>
                    </div>
                    <div
                        class="flex items-center gap-2 rounded-2xl bg-white/80 px-4 py-3"
                    >
                        <CalendarDays class="size-4 text-orange-600" />
                        <span
                            >Tanggal rilis:
                            {{ formatDate(latestRelease.released_at) }}</span
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        Riwayat Perubahan
                    </h2>
                    <p class="text-sm text-slate-500">
                        Dokumentasi formal changelog dimulai dari versi
                        {{ currentVersion }}.
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <article
                    v-for="release in releases"
                    :key="release.version"
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                    >
                        <div class="space-y-2">
                            <div
                                class="flex items-center gap-2 text-xs font-semibold tracking-[0.22em] text-orange-700 uppercase"
                            >
                                <span>v{{ release.version }}</span>
                                <span class="text-slate-300">•</span>
                                <span>{{
                                    formatDate(release.released_at)
                                }}</span>
                            </div>
                            <h3 class="text-xl font-semibold text-slate-900">
                                {{ release.title }}
                            </h3>
                            <p
                                class="max-w-3xl text-sm leading-6 text-slate-600"
                            >
                                {{ release.summary }}
                            </p>
                        </div>
                    </div>

                    <ul class="mt-4 grid gap-3 md:grid-cols-2">
                        <li
                            v-for="highlight in release.highlights"
                            :key="highlight"
                            class="rounded-2xl border border-slate-200/80 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700"
                        >
                            {{ highlight }}
                        </li>
                    </ul>
                </article>
            </div>
        </section>
    </div>
</template>
