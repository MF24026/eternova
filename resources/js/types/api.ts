export interface Meta {
    request_id: string
    tenant_id?: string
}

export interface Resource<T> {
    data: T
    meta: Meta
}

export interface PaginatedLinks {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
}

export interface PaginatedMeta extends Meta {
    current_page: number
    per_page: number
    total: number
    last_page: number
}

export interface Paginated<T> {
    data: T[]
    links: PaginatedLinks
    meta: PaginatedMeta
}

export interface ApiErrorResponse {
    message: string
    errors?: Record<string, string[]>
    error_code?: string
    feature?: string
    current_plan?: string
    required_plan?: string
    meta?: Meta
}
