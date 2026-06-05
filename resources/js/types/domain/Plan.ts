export interface Plan {
    id: number
    slug: 'basico' | 'pro' | 'enterprise'
    name: string
    description: string
    price_monthly_cents: number
    price_yearly_cents: number
    currency: string
    features: string[]
    limits: Record<string, number | boolean | null>
    sort_order: number
}
