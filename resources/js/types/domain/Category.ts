export interface Category {
    id: number
    name: string
    slug: string
    description: string | null
    image_url: string | null
    parent_id: number | null
    parent: Category | null
    children: Category[]
    sort_order: number
    is_active: boolean
    products_count: number
    depth: number
    deleted_at: string | null
    created_at: string | null
    updated_at: string | null
}

export interface CategoryInput {
    name: string
    slug?: string
    description?: string | null
    image_url?: string | null
    parent_id?: number | null
    sort_order?: number
    is_active?: boolean
}

export interface CategoryReorderItem {
    id: number
    sort_order: number
    parent_id: number | null
}
