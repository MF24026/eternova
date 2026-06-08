export interface StorefrontTenant {
    slug: string
    business_name: string
    logo_url: string | null
    primary_color: string | null
    secondary_color: string | null
    favicon_url: string | null
    currency: string
    country_code: string
    language: string
    whatsapp_number: string | null
    tagline?: string | null
    description?: string | null
}

export interface StorefrontVariant {
    id: number
    sku: string
    price_cents: number | null
    options: Record<string, string>
    image_url: string | null
    in_stock: boolean
    low_stock: boolean
}

export interface StorefrontProductImage {
    thumbnail: string
    medium: string
    full: string
}

export interface StorefrontProduct {
    id: number
    name: string
    slug: string
    description: string | null
    base_price_cents: number
    default_image_url: string | null
    gallery: StorefrontProductImage[]
    is_featured: boolean
    tax_rate: number | null
    categories: { name: string; slug: string }[]
    tags: { name: string; slug: string }[]
    variants?: StorefrontVariant[]
}

export interface StorefrontCategory {
    id: number
    name: string
    slug: string
    image_url: string | null
    sort_order: number
    children: StorefrontCategory[]
    products_count: number
}

export type StorefrontSortOption = 'featured' | 'price_asc' | 'price_desc' | 'newest'

export interface StorefrontProductFilters {
    category_slug?: string
    tag_slug?: string
    search?: string
    sort?: StorefrontSortOption
    page?: number
    per_page?: number
}
