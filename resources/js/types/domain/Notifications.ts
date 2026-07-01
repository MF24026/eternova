/**
 * A notification row as returned by GET /api/v1/notifications.
 * Display fields live inside `payload` (heterogeneous per `type`); the
 * presenter (notificationPresenter.ts) turns them into title/body/icon.
 */
export interface AppNotification {
    id: string
    type: string
    payload: Record<string, unknown>
    read_at: string | null
    created_at: string
}

/** The display shape produced by the presenter for a given notification. */
export interface PresentedNotification {
    /** A lucide-vue-next component. Typed `unknown` to match the AdminLayout NavItem convention. */
    icon: unknown
    title: string
    body: string
}
