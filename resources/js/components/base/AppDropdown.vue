<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

interface Props {
    label?: string
    align?: 'left' | 'right'
    width?: string
}

const props = withDefaults(defineProps<Props>(), {
    label: 'Opciones',
    align: 'right',
    width: '200px',
})
const open = ref(false)
const containerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)

function toggle(): void {
    open.value = !open.value
    if (open.value) {
        void nextTick(() => {
            const firstItem = menuRef.value?.querySelector<HTMLElement>('[role="menuitem"]')
            firstItem?.focus()
        })
    }
}

function close(): void {
    open.value = false
}

function onKeydown(e: KeyboardEvent): void {
    if (!open.value) return
    const items = Array.from(
        menuRef.value?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [],
    )
    const current = document.activeElement as HTMLElement
    const idx = items.indexOf(current)

    if (e.key === 'Escape') {
        close()
        containerRef.value?.querySelector<HTMLElement>('button')?.focus()
    } else if (e.key === 'ArrowDown') {
        e.preventDefault()
        items[Math.min(idx + 1, items.length - 1)]?.focus()
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        items[Math.max(idx - 1, 0)]?.focus()
    } else if (e.key === 'Home') {
        e.preventDefault()
        items[0]?.focus()
    } else if (e.key === 'End') {
        e.preventDefault()
        items[items.length - 1]?.focus()
    }
}

function onClickOutside(e: MouseEvent): void {
    if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
        close()
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
    <div ref="containerRef" class="relative inline-block" @keydown="onKeydown">
        <!-- Trigger -->
        <slot name="trigger" :toggle="toggle" :open="open">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant hover:text-primary transition-colors"
                :aria-haspopup="true"
                :aria-expanded="open"
                @click="toggle"
            >
                {{ props.label }}
                <ChevronDown
                    :size="14"
                    class="transition-transform duration-150"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </button>
        </slot>

        <!-- Menu -->
        <Transition
            enter-active-class="transition-all duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="open"
                ref="menuRef"
                :class="[
                    'absolute z-50 mt-1 rounded-xl bg-surface-lowest shadow-[var(--shadow-lifted)] py-1 dark:bg-surface-mid overflow-hidden',
                    props.align === 'right' ? 'right-0' : 'left-0',
                ]"
                :style="{ width: props.width }"
                role="menu"
                aria-orientation="vertical"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
