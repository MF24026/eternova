<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { X, Minus, Plus, ShoppingBag } from 'lucide-vue-next'
import StorefrontVariantSelector from '@/components/composite/storefront/StorefrontVariantSelector.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import StorefrontService from '@/services/StorefrontService'
import { useStorefrontStore } from '@/stores/storefront'
import { useCartStore } from '@/stores/cart'
import { useToast } from '@/composables/useToast'
import { useQuickAdd } from '@/composables/useQuickAdd'
import type { StorefrontProduct, StorefrontVariant } from '@/types/domain/Storefront'

const { isOpen, product, close } = useQuickAdd()
const storefront = useStorefrontStore()
const cart = useCartStore()
const toast = useToast()

const full = ref<StorefrontProduct | null>(null)
const loading = ref(false)
const resolved = ref<StorefrontVariant | null>(null)
const qty = ref(1)

const variants = computed<StorefrontVariant[]>(() => full.value?.variants ?? [])
const hasOptions = computed(() =>
    variants.value.some((v) => Object.keys(v.options).length > 0),
)
const allOutOfStock = computed(
    () => variants.value.length > 0 && variants.value.every((v) => !v.in_stock),
)
const priceCents = computed(
    () => resolved.value?.price_cents ?? product.value?.base_price_cents ?? 0,
)
const canAdd = computed(() => !loading.value && resolved.value !== null && resolved.value.in_stock)

async function load(slug: string): Promise<void> {
    loading.value = true
    resolved.value = null
    qty.value = 1
    try {
        const p = await StorefrontService.product(slug)
        full.value = p
        // Single / option-less product: resolve the first in-stock variant directly,
        // since the option selector renders (and resolves) nothing without options.
        if (!hasOptions.value) {
            resolved.value = (p.variants ?? []).find((v) => v.in_stock) ?? (p.variants ?? [])[0] ?? null
        }
    } catch {
        toast.error('No se pudo cargar el producto')
        close()
    } finally {
        loading.value = false
    }
}

watch(isOpen, (open) => {
    if (open && product.value) {
        full.value = null
        void load(product.value.slug)
        document.addEventListener('keydown', onKeydown)
    } else {
        document.removeEventListener('keydown', onKeydown)
    }
})

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') close()
}
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))

function inc(): void { qty.value += 1 }
function dec(): void { qty.value = Math.max(1, qty.value - 1) }

function addToCart(): void {
    if (!canAdd.value || resolved.value === null || full.value === null) return
    cart.addItem({ variant: resolved.value, product: full.value, qty: qty.value })
    toast.success('Agregado al carrito')
    close()
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen && product"
                class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center"
                role="dialog"
                aria-modal="true"
                aria-labelledby="quick-add-title"
                data-testid="quick-add-sheet"
            >
                <div
                    class="absolute inset-0"
                    style="background: rgba(61,47,50,.42); backdrop-filter: blur(6px)"
                    @click="close"
                />

                <div
                    class="relative w-full sm:max-w-md bg-surface shadow-[var(--shadow-lifted)]
                           rounded-t-[var(--r-2xl)] sm:rounded-[var(--r-2xl)]
                           max-h-[88dvh] flex flex-col overflow-hidden
                           quick-add-panel"
                >
                    <!-- Header -->
                    <div class="flex items-center gap-3 p-4 sm:p-5">
                        <div class="w-14 h-14 rounded-[var(--r-lg)] overflow-hidden shrink-0" style="background: var(--surface-mid)">
                            <img
                                v-if="product.default_image_url"
                                :src="product.default_image_url"
                                :alt="product.name"
                                class="w-full h-full object-cover"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 id="quick-add-title" class="serif text-lg leading-tight text-on-surface truncate">
                                {{ product.name }}
                            </h2>
                            <p class="text-sm font-bold text-primary tabular-nums mt-0.5" data-testid="quick-add-price">
                                {{ storefront.formatPrice(priceCents) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn-icon shrink-0"
                            aria-label="Cerrar"
                            data-testid="quick-add-close"
                            @click="close"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="px-4 sm:px-5 pb-2 overflow-y-auto flex-1">
                        <div v-if="loading" class="flex justify-center py-10">
                            <AppSpinner />
                        </div>

                        <template v-else>
                            <p v-if="allOutOfStock" class="text-sm text-center py-6" style="color: var(--error)">
                                Sin stock por el momento.
                            </p>

                            <StorefrontVariantSelector
                                v-else-if="hasOptions"
                                :variants="variants"
                                @update:resolved="resolved = $event"
                            />
                        </template>
                    </div>

                    <!-- Footer: qty + add -->
                    <div
                        v-if="!loading && !allOutOfStock"
                        class="flex items-center gap-3 p-4 sm:p-5 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                        style="background: var(--surface-low)"
                    >
                        <div class="inline-flex items-center gap-0.5 rounded-full p-0.5" style="background: var(--surface-lowest)">
                            <button
                                type="button"
                                class="w-9 h-9 grid place-items-center rounded-full transition-colors hover:bg-surface-mid disabled:opacity-30"
                                :disabled="qty <= 1"
                                aria-label="Quitar uno"
                                @click="dec"
                            >
                                <Minus :size="15" />
                            </button>
                            <span class="min-w-[28px] text-center font-bold tabular-nums text-on-surface" data-testid="quick-add-qty">{{ qty }}</span>
                            <button
                                type="button"
                                class="w-9 h-9 grid place-items-center rounded-full transition-colors hover:bg-surface-mid"
                                aria-label="Agregar uno"
                                @click="inc"
                            >
                                <Plus :size="15" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="btn-primary flex-1 justify-center gap-2 disabled:opacity-45"
                            :disabled="!canAdd"
                            data-testid="quick-add-confirm"
                            @click="addToCart"
                        >
                            <ShoppingBag :size="16" aria-hidden="true" />
                            {{ resolved && !resolved.in_stock ? 'Sin stock' : 'Agregar al carrito' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.quick-add-panel {
    animation: quick-add-rise .28s cubic-bezier(.2, .8, .2, 1) both;
}
@keyframes quick-add-rise {
    from { transform: translateY(24px); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .quick-add-panel { animation: none; }
}
</style>
