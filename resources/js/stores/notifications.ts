import { defineStore } from 'pinia'
import { ref } from 'vue'
import { NotificationsService } from '@/services/NotificationsService'
import { useToast } from '@/composables/useToast'
import type { AppNotification } from '@/types/domain/Notifications'

const POLL_MS = 60_000

export const useNotificationsStore = defineStore('notifications', () => {
    const unreadCount = ref(0)
    const items = ref<AppNotification[]>([])
    const loading = ref(false)
    const open = ref(false)

    let timer: ReturnType<typeof setInterval> | null = null
    let visibilityBound = false

    async function fetchUnreadCount(): Promise<void> {
        try {
            unreadCount.value = await NotificationsService.getUnreadCount()
        } catch {
            // Keep last-known count; polling will retry. Silent by design (background call).
        }
    }

    async function fetchList(): Promise<void> {
        loading.value = true
        try {
            items.value = await NotificationsService.list(false, 15)
        } catch {
            useToast().error('No pudimos cargar las notificaciones')
        } finally {
            loading.value = false
        }
    }

    async function markRead(id: string): Promise<void> {
        const target = items.value.find((n) => n.id === id)
        if (!target || target.read_at) return
        target.read_at = new Date().toISOString()
        unreadCount.value = Math.max(0, unreadCount.value - 1)
        try {
            await NotificationsService.markRead(id)
        } catch {
            useToast().error('No pudimos marcar la notificacion')
            await Promise.all([fetchUnreadCount(), fetchList()])
        }
    }

    async function markAllRead(): Promise<void> {
        const now = new Date().toISOString()
        items.value.forEach((n) => {
            if (!n.read_at) n.read_at = now
        })
        unreadCount.value = 0
        try {
            await NotificationsService.markAllRead()
        } catch {
            useToast().error('No pudimos marcar todas como leidas')
            await Promise.all([fetchUnreadCount(), fetchList()])
        }
    }

    function toggle(): void {
        open.value = !open.value
        if (open.value) void fetchList()
    }

    function close(): void {
        open.value = false
    }

    function onVisibility(): void {
        if (!document.hidden) void fetchUnreadCount()
    }

    function startPolling(): void {
        void fetchUnreadCount()
        if (timer === null) {
            timer = setInterval(() => {
                if (!document.hidden) void fetchUnreadCount()
            }, POLL_MS)
        }
        if (!visibilityBound) {
            document.addEventListener('visibilitychange', onVisibility)
            visibilityBound = true
        }
    }

    function stopPolling(): void {
        if (timer !== null) {
            clearInterval(timer)
            timer = null
        }
        if (visibilityBound) {
            document.removeEventListener('visibilitychange', onVisibility)
            visibilityBound = false
        }
    }

    return {
        unreadCount,
        items,
        loading,
        open,
        fetchUnreadCount,
        fetchList,
        markRead,
        markAllRead,
        toggle,
        close,
        startPolling,
        stopPolling,
    }
})
