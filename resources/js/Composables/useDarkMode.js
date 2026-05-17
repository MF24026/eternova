import { ref, watch } from 'vue';

const isDark = ref(
    localStorage.getItem('dark-mode') === 'true' ||
    (!localStorage.getItem('dark-mode') && window.matchMedia('(prefers-color-scheme: dark)').matches)
);

export function useDarkMode() {
    const toggle = () => {
        isDark.value = !isDark.value;
    };

    watch(isDark, (value) => {
        localStorage.setItem('dark-mode', String(value));
        document.documentElement.classList.toggle('dark', value);
    }, { immediate: true });

    return {
        isDark,
        toggle,
    };
}
