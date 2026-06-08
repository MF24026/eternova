export interface ProductOptionValue {
    id: number
    value: string
    position: number
}

export interface ProductOption {
    id: number
    name: string
    position: number
    values: ProductOptionValue[]
}

export interface ProductVariant {
    id: number
    product_id: number
    sku: string
    barcode: string | null
    price_cents: number | null
    cost_price_cents: number | null
    weight_grams: number | null
    options: Record<string, string>
    image_url: string | null
    position: number
    deleted_at: string | null
    created_at: string | null
    updated_at: string | null
}

export interface Tag {
    id: number
    name: string
    slug: string
    created_at: string | null
    updated_at: string | null
}

export interface Product {
    id: number
    name: string
    slug: string
    description: string | null
    sku_root: string | null
    base_price_cents: number
    cost_price_cents: number | null
    default_image_url: string | null
    gallery: string[]
    is_active: boolean
    is_featured: boolean
    tax_rate: string | null
    variants: ProductVariant[]
    options: ProductOption[]
    categories: import('./Category').Category[]
    tags: Tag[]
    deleted_at: string | null
    created_at: string | null
    updated_at: string | null
}

export interface ProductVariantInput {
    sku?: string
    barcode?: string | null
    price_cents?: number | null
    cost_price_cents?: number | null
    weight_grams?: number | null
    options?: Record<string, string>
    image_url?: string | null
    position?: number
}

export interface ProductOptionInput {
    name: string
    values: string[]
}

export interface ProductInput {
    name: string
    slug?: string
    description?: string | null
    sku_root?: string | null
    base_price_cents: number
    cost_price_cents?: number | null
    default_image_url?: string | null
    gallery?: string[]
    is_active?: boolean
    is_featured?: boolean
    tax_rate?: number | null
    options?: ProductOptionInput[]
    variants?: ProductVariantInput[]
    categories?: number[]
    tags?: number[]
}

export interface TagInput {
    name: string
    slug?: string
}
