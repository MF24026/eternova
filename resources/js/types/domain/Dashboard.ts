// Dashboard summary — shape returned by GET /api/v1/dashboard.

export interface DashboardKpis {
    today_sales_cents: number
    pending_orders: number
    low_stock_count: number
    month_expenses_cents: number
}

export interface SalesPoint {
    date: string // YYYY-MM-DD
    total_cents: number
}

export interface TopProduct {
    name: string
    units: number
}

export interface RecentOrder {
    id: string
    order_number: string
    customer_name: string | null
    total_cents: number
    status: string
    created_at: string | null
}

export interface DashboardSummary {
    kpis: DashboardKpis
    sales_series: SalesPoint[]
    top_products: TopProduct[]
    recent_orders: RecentOrder[]
    range_days: number
}

export type DashboardRange = 7 | 14 | 30
