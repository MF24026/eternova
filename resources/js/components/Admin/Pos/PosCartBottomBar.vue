<script setup lang="ts">
import { computed } from 'vue'
import { ShoppingBag } from 'lucide-vue-next'

interface Props {
    lineCount: number
    totalCents: number
    formatCents: (cents: number) => string
}

const props = defineProps<Props>()

const emit = defineEmits<{
    open: []
}>()

const itemLabel = computed(() =>
    props.lineCount === 1 ? '1 artículo' : `${props.lineCount} artículos`,
)
</script>

<template>
    <!--
        Visible only on mobile (< md).
        Floats above the page content via fixed positioning.
        Hidden when cart is empty — no point showing a $0.00 CTA.
    -->
    <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        leave-active-class="transition-transform duration-200 ease-in"
        enter-from-class="translate-y-full"
        leave-to-class="translate-y-full"
    >
        <div
            v-if="lineCount > 0"
            class="md:hidden fixed bottom-0 left-0 right-0 z-[70] px-4 pb-safe-area-inset-bottom"
            style="padding-bottom: max(1rem, env(safe-area-inset-bottom))"
        >
            <button
                type="button"
                class="btn btn-primary w-full justify-between text-base"
                style="
                    padding: 16px 24px;
                    border-radius: var(--r-xl);
                    box-shadow: var(--shadow-lifted);
                "
                aria-label="Ver carrito"
                data-testid="pos-cart-bottom-bar"
                @click="emit('open')"
            >
                <span class="flex items-center gap-2">
                    <ShoppingBag :size="18" aria-hidden="true" />
                    <span class="font-semibold">{{ itemLabel }}</span>
                </span>
                <span class="serif text-xl tabular-nums tracking-tighter">
                    {{ formatCents(totalCents) }}
                </span>
            </button>
        </div>
    </Transition>
</template>
