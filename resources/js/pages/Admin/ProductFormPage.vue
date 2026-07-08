<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeft, Save, Settings2 } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ProductVariantsEditor from '@/components/composite/ProductVariantsEditor.vue'
import ProductVariantsOverlay from '@/components/Admin/Products/ProductVariantsOverlay.vue'
import TagSelector from '@/components/composite/TagSelector.vue'
import CategoryMultiSelect from '@/components/composite/CategoryMultiSelect.vue'
import ImageUploader from '@/components/composite/ImageUploader.vue'
import ImageGallery from '@/components/composite/ImageGallery.vue'
import { useProductsStore } from '@/stores/products'
import { useCategoriesStore } from '@/stores/categories'
import { useTagsStore } from '@/stores/tags'
import { useToast } from '@/composables/useToast'
import { useImageUpload } from '@/composables/useImageUpload'
import ProductImagesService from '@/services/ProductImagesService'
import type { ProductInput, ProductOptionInput, ProductImage } from '@/types/domain/Product'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

const router = useRouter()
const route  = useRoute()
const store  = useProductsStore()
const categoriesStore = useCategoriesStore()
const tagsStore       = useTagsStore()
const toast  = useToast()

const productId = computed<number | null>(() => {
    const id = route.params.id
    const parsed = parseInt(Array.isArray(id) ? id[0] : id, 10)
    return isNaN(parsed) ? null : parsed
})

const isEdit = computed(() => productId.value !== null)
const variantsOverlayOpen = ref(false)

// ── Active tab ─────────────────────────────────────────────────────────────────
const activeTab = ref<'basic' | 'variants' | 'images' | 'relations' | 'seo'>('basic')

// ── Form state ─────────────────────────────────────────────────────────────────
interface VariantRow {
    key: string
    options: Record<string, string>
    sku: string
    price_cents: number | null
    image_url: string | null
}

const form = ref({
    name:              '',
    description:       '',
    sku_root:          '',
    base_price_cents:  '',    // string for input binding, parsed on submit
    cost_price_cents:  '',
    default_image_url: null as string | null,
    gallery:           [] as ProductImage[],
    is_active:         true,
    is_featured:       false,
    tax_rate:          '',
    slug:              '',
    categories:        [] as number[],
    tags:              [] as number[],
})

// ── Image upload state ─────────────────────────────────────────────────────────
const { isUploading, uploadProgress, uploadError, upload } = useImageUpload()
const deletingUrl       = ref<string | null>(null)
const settingDefaultUrl = ref<string | null>(null)

const options   = ref<ProductOptionInput[]>([])
const variants  = ref<VariantRow[]>([])

const isSaving  = ref(false)
const isLoading = ref(false)
const formErrors = ref<Record<string, string[]>>({})

function fieldError(field: string): string {
    return formErrors.value[field]?.[0] ?? ''
}

// ── Load on edit ───────────────────────────────────────────────────────────────
onMounted(async () => {
    document.title = isEdit.value ? 'Editar Producto — Eternova' : 'Nuevo Producto — Eternova'

    void categoriesStore.fetchList({ is_active: true })
    void tagsStore.fetchList({ per_page: 100 })

    if (isEdit.value && productId.value !== null) {
        isLoading.value = true
        try {
            await store.fetchOne(productId.value)
            const p = store.current
            if (p !== null) {
                form.value = {
                    name:              p.name,
                    description:       p.description ?? '',
                    sku_root:          p.sku_root ?? '',
                    base_price_cents:  String(p.base_price_cents),
                    cost_price_cents:  p.cost_price_cents !== null ? String(p.cost_price_cents) : '',
                    default_image_url: p.default_image_url ?? null,
                    gallery:           p.gallery ?? [],
                    is_active:         p.is_active,
                    is_featured:       p.is_featured,
                    tax_rate:          p.tax_rate !== null ? String(parseFloat(String(p.tax_rate)) * 100) : '',
                    slug:              p.slug,
                    categories:        p.categories.map((c) => c.id),
                    tags:              p.tags.map((t) => t.id),
                }
                options.value = p.options.map((o) => ({
                    name:   o.name,
                    values: o.values.map((v) => v.value),
                }))
            }
        } finally {
            isLoading.value = false
        }
    }
})

// ── Submit ─────────────────────────────────────────────────────────────────────
async function submit(): Promise<void> {
    isSaving.value = true
    formErrors.value = {}

    const basePriceParsed = Math.round(parseFloat(form.value.base_price_cents || '0') * 100)
    const costPriceParsed = form.value.cost_price_cents !== ''
        ? Math.round(parseFloat(form.value.cost_price_cents) * 100)
        : null

    const payload: ProductInput = {
        name:              form.value.name,
        base_price_cents:  basePriceParsed,
        is_active:         form.value.is_active,
        is_featured:       form.value.is_featured,
        description:       form.value.description || null,
        sku_root:          form.value.sku_root || null,
        cost_price_cents:  costPriceParsed,
        // Images are managed via the dedicated image endpoints, not the product payload.
        tax_rate:          form.value.tax_rate !== '' ? parseFloat(form.value.tax_rate) / 100 : null,
        categories:        form.value.categories,
        tags:              form.value.tags,
    }

    if (form.value.slug.trim() !== '') {
        payload.slug = form.value.slug.trim()
    }

    // Only send options/variants for new products (edit manages them via variant sub-resource)
    if (!isEdit.value) {
        if (options.value.length > 0) {
            payload.options = options.value
        }
        if (variants.value.length > 0) {
            payload.variants = variants.value.map((r) => ({
                sku:         r.sku,
                options:     r.options,
                price_cents: r.price_cents,
                image_url:   r.image_url,
            }))
        }
    }

    try {
        if (isEdit.value && productId.value !== null) {
            await store.update(productId.value, payload)
            toast.success('Producto actualizado')
        } else {
            const created = await store.create(payload)
            toast.success('Producto creado')
            void router.push({ name: 'admin.products.edit', params: { id: created.id } })
            return
        }
    } catch (err) {
        const axiosError = err as AxiosError<ApiErrorResponse>
        if (axiosError.response?.status === 422 && axiosError.response.data.errors) {
            formErrors.value = axiosError.response.data.errors as Record<string, string[]>
            activeTab.value = 'basic'
        } else {
            toast.error('Error al guardar el producto')
        }
    } finally {
        isSaving.value = false
    }
}

// ── Image actions ──────────────────────────────────────────────────────────────

async function handleImageSelected(file: File): Promise<void> {
    if (productId.value === null) {
        toast.error('Guarda el producto primero antes de subir imagenes.')
        return
    }

    const image = await upload(productId.value, file)
    if (image !== null) {
        form.value.gallery.push(image)
        // Auto-set as default if this is the first image.
        if (form.value.default_image_url === null) {
            form.value.default_image_url = image.full
        }
        toast.success('Imagen subida')
    }
}

async function handleSetDefault(fullUrl: string): Promise<void> {
    if (productId.value === null) return

    settingDefaultUrl.value = fullUrl
    try {
        const updated = await ProductImagesService.setDefault(productId.value, fullUrl)
        form.value.default_image_url = updated.default_image_url
        toast.success('Imagen principal actualizada')
    } catch {
        toast.error('Error al establecer la imagen principal')
    } finally {
        settingDefaultUrl.value = null
    }
}

async function handleDeleteImage(fullUrl: string): Promise<void> {
    if (productId.value === null) return

    deletingUrl.value = fullUrl
    try {
        await ProductImagesService.delete(productId.value, fullUrl)

        form.value.gallery = form.value.gallery.filter((img) => img.full !== fullUrl)

        if (form.value.default_image_url === fullUrl) {
            form.value.default_image_url = form.value.gallery[0]?.full ?? null
        }

        toast.success('Imagen eliminada')
    } catch {
        toast.error('Error al eliminar la imagen')
    } finally {
        deletingUrl.value = null
    }
}

async function handleReorder(orderedFullUrls: string[]): Promise<void> {
    if (productId.value === null) return

    // Optimistic reorder — update local state immediately.
    const indexed = Object.fromEntries(form.value.gallery.map((img) => [img.full, img]))
    form.value.gallery = orderedFullUrls
        .filter((url) => indexed[url] !== undefined)
        .map((url) => indexed[url])

    try {
        await ProductImagesService.reorder(productId.value, orderedFullUrls)
    } catch {
        toast.error('Error al reordenar las imagenes')
    }
}

const TABS = [
    { key: 'basic',     label: 'Datos basicos' },
    { key: 'variants',  label: 'Opciones y variantes' },
    { key: 'images',    label: 'Imagenes' },
    { key: 'relations', label: 'Categorías y tags' },
    { key: 'seo',       label: 'SEO' },
] as const
</script>

<template>
    <div class="flex flex-col gap-4 pb-8">
        <!-- Page header -->
        <div class="flex items-center gap-3">
            <button
                class="btn-icon"
                aria-label="Volver"
                @click="router.push({ name: 'admin.products' })"
            >
                <ArrowLeft :size="18" />
            </button>
            <div class="grow">
                <p class="label-gilt">{{ isEdit ? 'Editar producto' : 'Nuevo producto' }}</p>
                <p class="serif text-2xl text-on-surface">
                    {{ form.name || 'Sin nombre' }}
                </p>
            </div>
            <AppButton :icon="Save" :disabled="isSaving" @click="submit">
                {{ isSaving ? 'Guardando...' : 'Guardar' }}
            </AppButton>
        </div>

        <!-- Loading state -->
        <div v-if="isLoading" class="flex justify-center py-12">
            <AppSpinner />
        </div>

        <template v-else>
            <!-- Tabs -->
            <div class="tabs inline-flex flex-wrap gap-1">
                <button
                    v-for="tab in TABS"
                    :key="tab.key"
                    :class="['tab', { active: activeTab === tab.key }]"
                    @click="activeTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Tab: Datos basicos -->
            <div v-show="activeTab === 'basic'" class="card p-6 flex flex-col gap-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <AppInput
                        v-model="form.name"
                        label="Nombre del producto"
                        placeholder="ej. Rosa Eterna Carmesi"
                        required
                        :error="fieldError('name')"
                    />
                    <AppInput
                        v-model="form.sku_root"
                        label="SKU raiz"
                        placeholder="ej. ROSA-CAR"
                        help-text="Prefijo para las variantes"
                        :error="fieldError('sku_root')"
                    />
                </div>

                <div class="field">
                    <label class="label-gilt mb-1 block">Descripción</label>
                    <textarea
                        v-model="form.description"
                        rows="4"
                        placeholder="Descripción del producto..."
                        class="w-full px-4 py-2.5 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary/30"
                        style="background: var(--surface-low); color: var(--on-surface)"
                    />
                    <p v-if="fieldError('description')" class="text-xs text-error mt-1">{{ fieldError('description') }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <AppInput
                        v-model="form.base_price_cents"
                        label="Precio base"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        required
                        :error="fieldError('base_price_cents')"
                    />
                    <AppInput
                        v-model="form.cost_price_cents"
                        label="Costo"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        :error="fieldError('cost_price_cents')"
                    />
                </div>

                <!-- Toggle: Active / Featured -->
                <div class="flex flex-col gap-3">
                    <div
                        v-for="field in [
                            { key: 'is_active', label: 'Activo en catálogo' },
                            { key: 'is_featured', label: 'Producto destacado' },
                        ]"
                        :key="field.key"
                        class="flex items-center justify-between p-3 rounded-xl"
                        style="background: var(--surface-low)"
                    >
                        <span class="text-sm text-on-surface">{{ field.label }}</span>
                        <button
                            type="button"
                            :class="[
                                'w-10 h-6 rounded-full transition-colors duration-200 relative',
                                (form as Record<string, unknown>)[field.key] ? 'bg-primary' : 'bg-on-surface-variant/30'
                            ]"
                            :aria-checked="(form as Record<string, unknown>)[field.key] as boolean"
                            role="switch"
                            @click="(form as Record<string, unknown>)[field.key] = !(form as Record<string, unknown>)[field.key]"
                        >
                            <span
                                :class="[
                                    'absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200',
                                    (form as Record<string, unknown>)[field.key] ? 'translate-x-5' : 'translate-x-1'
                                ]"
                            />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab: Opciones y variantes -->
            <div v-show="activeTab === 'variants'" class="card p-6">
                <p class="label-gilt mb-4">
                    {{ isEdit ? 'Variantes del producto' : 'Configurar opciones y variantes' }}
                </p>

                <div v-if="isEdit" class="flex items-center justify-between gap-3 mb-4">
                    <p class="text-sm text-on-surface-variant">
                        Editá el SKU y el precio de cada variante, agregá o eliminá combinaciones.
                    </p>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :icon="Settings2"
                        type="button"
                        data-testid="btn-manage-variants"
                        @click="variantsOverlayOpen = true"
                    >
                        Gestionar variantes
                    </AppButton>
                </div>

                <ProductVariantsEditor
                    v-if="!isEdit"
                    v-model="variants"
                    :options="options"
                    :base-price-cents="parseInt(form.base_price_cents || '0') * 100"
                    @update:options="(o) => (options = o)"
                />

                <!-- Edit mode: show existing variants -->
                <div v-else-if="store.current?.variants?.length">
                    <div class="overflow-x-auto rounded-xl" style="background: var(--surface-low)">
                        <table class="w-full text-sm">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--outline-variant)">
                                    <th class="text-left px-4 py-3 text-on-surface-variant font-medium">SKU</th>
                                    <th class="text-left px-4 py-3 text-on-surface-variant font-medium">Opciones</th>
                                    <th class="text-left px-4 py-3 text-on-surface-variant font-medium">Precio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="variant in store.current?.variants"
                                    :key="variant.id"
                                    style="border-bottom: 1px solid var(--outline-variant)"
                                    class="last:border-b-0"
                                >
                                    <td class="px-4 py-2 text-on-surface font-mono text-xs">{{ variant.sku }}</td>
                                    <td class="px-4 py-2 text-on-surface">
                                        <span
                                            v-for="(val, key) in variant.options"
                                            :key="key"
                                            class="mr-2 text-xs"
                                        >
                                            <span class="text-on-surface-variant">{{ key }}:</span>
                                            {{ val }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-on-surface">
                                        {{ variant.price_cents !== null
                                            ? '$' + (variant.price_cents / 100).toFixed(2)
                                            : 'Heredado' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p v-else class="text-sm text-on-surface-variant">Sin variantes. Las variantes se generan al definir opciones durante la creación.</p>
            </div>

            <!-- Tab: Imagenes -->
            <div v-show="activeTab === 'images'" class="card p-6 flex flex-col gap-5">
                <p class="label-gilt">Galeria de imagenes</p>

                <!-- Uploader — disabled for new products until they are saved -->
                <div v-if="!isEdit" class="text-sm text-on-surface-variant p-4 rounded-xl" style="background: var(--surface-low)">
                    Guarda el producto primero para poder subir imagenes.
                </div>
                <ImageUploader
                    v-else
                    :is-uploading="isUploading"
                    :upload-progress="uploadProgress"
                    :upload-error="uploadError ?? undefined"
                    @select="handleImageSelected"
                />

                <!-- Current gallery -->
                <ImageGallery
                    v-if="form.gallery.length > 0 || isEdit"
                    :images="form.gallery"
                    :default-image-url="form.default_image_url"
                    :deleting-url="deletingUrl"
                    :setting-default-url="settingDefaultUrl"
                    @set-default="handleSetDefault"
                    @delete="handleDeleteImage"
                    @reorder="handleReorder"
                />
            </div>

            <!-- Tab: Categorías y tags -->
            <div v-show="activeTab === 'relations'" class="card p-6 flex flex-col gap-6">
                <div class="field">
                    <label class="label-gilt mb-2 block">Categorías</label>
                    <CategoryMultiSelect
                        v-model="form.categories"
                        :available-categories="categoriesStore.items"
                    />
                    <p v-if="fieldError('categories')" class="text-xs text-error mt-1">{{ fieldError('categories') }}</p>
                </div>

                <div class="field">
                    <label class="label-gilt mb-2 block">Etiquetas</label>
                    <TagSelector
                        v-model="form.tags"
                        :available-tags="tagsStore.items"
                    />
                    <p v-if="fieldError('tags')" class="text-xs text-error mt-1">{{ fieldError('tags') }}</p>
                </div>
            </div>

            <!-- Tab: SEO -->
            <div v-show="activeTab === 'seo'" class="card p-6 flex flex-col gap-4">
                <AppInput
                    v-model="form.slug"
                    label="Slug (URL)"
                    placeholder="se genera automáticamente desde el nombre"
                    help-text="Solo letras minusculas, numeros y guiones"
                    :error="fieldError('slug')"
                />
                <AppInput
                    v-model="form.tax_rate"
                    label="Tasa de impuesto (%)"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    placeholder="0"
                    :error="fieldError('tax_rate')"
                />
            </div>

            <!-- Bottom actions -->
            <div class="flex justify-end gap-3">
                <AppButton
                    variant="secondary"
                    @click="router.push({ name: 'admin.products' })"
                >
                    Cancelar
                </AppButton>
                <AppButton :icon="Save" :disabled="isSaving" @click="submit">
                    {{ isSaving ? 'Guardando...' : 'Guardar producto' }}
                </AppButton>
            </div>
        </template>

        <!-- Edit-mode variant management overlay -->
        <ProductVariantsOverlay
            :show="variantsOverlayOpen"
            @close="variantsOverlayOpen = false"
        />
    </div>
</template>
