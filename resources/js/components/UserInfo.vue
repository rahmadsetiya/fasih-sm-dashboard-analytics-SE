<script setup lang="ts">
import { computed } from 'vue';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

const props = withDefaults(defineProps<{ user: User; showEmail?: boolean }>(), {
    showEmail: false,
});

const { getInitials } = useInitials();
const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <div
        class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sidebar-accent text-sidebar-foreground"
    >
        <img
            v-if="showAvatar"
            :src="user.avatar!"
            :alt="user.name"
            class="size-full object-cover"
        />
        <span v-else class="text-xs font-semibold">{{
            getInitials(user.name)
        }}</span>
    </div>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span
            v-if="showEmail"
            class="truncate text-xs text-muted-foreground"
            >{{ user.email }}</span
        >
    </div>
</template>
