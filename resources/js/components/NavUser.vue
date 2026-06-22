<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, LogOut, Settings } from '@lucide/vue';
import Popover from 'primevue/popover';
import { computed, ref } from 'vue';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth.user as User);
const op = ref();

function toggle(event: Event) {
    op.value.toggle(event);
}
</script>

<template>
    <button
        class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors hover:bg-sidebar-accent/60 focus:outline-none"
        @click="toggle"
        aria-haspopup="true"
    >
        <UserInfo :user="user" />
        <ChevronsUpDown class="ml-auto size-4 shrink-0 text-muted-foreground" />
    </button>

    <Popover ref="op">
        <div class="min-w-48 py-1">
            <div
                class="flex items-center gap-2 border-b border-border px-3 py-2"
            >
                <UserInfo :user="user" :show-email="true" />
            </div>

            <div class="py-1">
                <Link
                    :href="edit()"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                    @click="op.hide()"
                >
                    <Settings class="size-4" />
                    Settings
                </Link>
            </div>

            <div class="border-t border-border py-1">
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                    data-test="logout-button"
                >
                    <LogOut class="size-4" />
                    Log out
                </Link>
            </div>
        </div>
    </Popover>
</template>
