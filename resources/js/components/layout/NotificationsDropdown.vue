<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Bell, CheckCheck } from 'lucide-vue-next'
import { useNotificationsStore } from '@/stores/notifications'
import { presentNotification } from './notificationPresenter'
import { useFormatDate } from '@/composables/useFormatDate'

const store = useNotificationsStore()
const { formatRelative } = useFormatDate()
const root = ref<HTMLElement | null>(null)

function onClickOutside(e: MouseEvent): void {
    if (!store.open) return
    const target = e.target as Node
    // Ignore clicks on the bell itself (it toggles the store) and inside the panel.
    if (root.value && !root.value.contains(target) && !(target as HTMLElement).closest?.('[data-notif-bell]')) {
        store.close()
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') store.close()
}

onMounted(() => {
    document.addEventListener('click', onClickOutside)
    document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <div
        v-if="store.open"
        ref="root"
        class="notif-panel"
        role="menu"
        data-testid="notif-panel"
    >
        <header class="notif-panel-head">
            <span class="notif-panel-title">Notificaciones</span>
            <button
                type="button"
                class="notif-mark-all"
                data-testid="notif-mark-all"
                :disabled="store.unreadCount === 0"
                @click="store.markAllRead()"
            >
                <CheckCheck :size="16" />
                <span>Marcar todas</span>
            </button>
        </header>

        <div v-if="store.loading" class="notif-empty">
            <span>Cargando...</span>
        </div>

        <ul v-else-if="store.items.length" class="notif-list">
            <li
                v-for="item in store.items"
                :key="item.id"
                class="notif-item"
                data-testid="notif-item"
                :class="{ 'is-unread': item.read_at === null }"
                @click="store.markRead(item.id)"
            >
                <span class="notif-item-icon" aria-hidden="true">
                    <component :is="presentNotification(item).icon" :size="18" />
                </span>
                <span class="notif-item-body">
                    <span class="notif-item-title">{{ presentNotification(item).title }}</span>
                    <span class="notif-item-text">{{ presentNotification(item).body }}</span>
                    <span class="notif-item-time">{{ formatRelative(item.created_at) }}</span>
                </span>
                <span v-if="item.read_at === null" class="notif-item-dot" aria-hidden="true" />
            </li>
        </ul>

        <div v-else class="notif-empty" data-testid="notif-empty">
            <Bell :size="24" />
            <span>No tienes notificaciones</span>
        </div>
    </div>
</template>

<style scoped>
.notif-panel {
    position: absolute;
    top: calc(100% + 0.5rem);
    right: 0;
    width: 22rem;
    max-width: calc(100vw - 2rem);
    max-height: 26rem;
    overflow-y: auto;
    background: var(--surface);
    border-radius: var(--r-lg);
    box-shadow: var(--shadow-lifted);
    z-index: 50;
}
.notif-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1rem;
    /* --tier-mid does not exist; --surface-mid is the closest surface elevation token */
    background: var(--surface-mid);
    border-top-left-radius: var(--r-lg);
    border-top-right-radius: var(--r-lg);
}
.notif-panel-title {
    font-weight: 600;
    color: var(--on-surface);
}
.notif-mark-all {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    color: var(--primary);
    background: transparent;
    cursor: pointer;
}
.notif-mark-all:disabled {
    opacity: 0.5;
    cursor: default;
}
.notif-list {
    display: flex;
    flex-direction: column;
}
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
}
.notif-item:hover {
    /* --tier does not exist; --surface-low is the closest subtle hover token */
    background: var(--surface-low);
}
.notif-item.is-unread {
    /* --tier-mid does not exist; --surface-mid is the closest surface elevation token */
    background: var(--surface-mid);
}
.notif-item-icon {
    color: var(--secondary);
    flex-shrink: 0;
    margin-top: 0.1rem;
}
.notif-item-body {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
    flex: 1;
}
.notif-item-title {
    font-weight: 600;
    font-size: 0.88rem;
    color: var(--on-surface);
}
.notif-item-text {
    font-size: 0.8rem;
    color: var(--on-surface-variant);
}
.notif-item-time {
    font-size: 0.72rem;
    color: var(--on-surface-variant);
    opacity: 0.75;
}
.notif-item-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: var(--r-full);
    background: var(--primary);
    flex-shrink: 0;
    margin-top: 0.35rem;
}
.notif-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 2rem 1rem;
    color: var(--on-surface-variant);
    font-size: 0.85rem;
}
</style>
