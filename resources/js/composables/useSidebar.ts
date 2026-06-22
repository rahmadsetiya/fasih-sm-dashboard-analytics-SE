import { ref } from 'vue';

const mobileOpen = ref(false);

export function useSidebar() {
    return {
        mobileOpen,
        toggleMobile: () => {
            mobileOpen.value = !mobileOpen.value;
        },
    };
}
