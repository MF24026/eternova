import { defineStore } from 'pinia'
import { ref } from 'vue'
import InventoryService, { type InventoryFilters, type MovementFilters } from '@/services/InventoryService'
import type { BranchInventory, InventoryMovement, RecordMovementInput, TransferStockInput } from '@/types/domain/Inventory'
import type { PaginatedMeta, PaginatedLinks } from '@/types/api'
import { useToast } from '@/composables/useToast'

interface InventoryPagination {
    meta: PaginatedMeta | null
    links: PaginatedLinks | null
}

export const useInventoryStore = defineStore('inventory', () => {
    const items = ref<BranchInventory[]>([])
    const current = ref<BranchInventory | null>(null)
    const pagination = ref<InventoryPagination>({ meta: null, links: null })
    const movements = ref<InventoryMovement[]>([])
    const movementsPagination = ref<InventoryPagination>({ meta: null, links: null })
    const isLoading = ref(false)
    const error = ref<string | null>(null)

    async function fetchList(filters: InventoryFilters = {}): Promise<void> {
        isLoading.value = true
        error.value = null
        try {
            const result = await InventoryService.list(filters)
            items.value = result.data
            pagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error al cargar el inventario'
            items.value = []
        } finally {
            isLoading.value = false
        }
    }

    async function fetchOne(id: number): Promise<void> {
        isLoading.value = true
        error.value = null
        try {
            current.value = await InventoryService.get(id)
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error al cargar el item de inventario'
            current.value = null
        } finally {
            isLoading.value = false
        }
    }

    async function recordMovement(input: RecordMovementInput): Promise<InventoryMovement> {
        const toast = useToast()
        const movement = await InventoryService.recordMovement(input)
        toast.success('Movimiento registrado')

        // Refresh the affected row if we have it in state, else refresh the whole list.
        const affectedIdx = items.value.findIndex(
            (item) =>
                item.branch_id === input.branch_id &&
                item.product_variant_id === input.product_variant_id,
        )
        if (affectedIdx !== -1) {
            await fetchOne(items.value[affectedIdx].id)
            if (current.value !== null) {
                items.value[affectedIdx] = current.value
            }
        } else if (items.value.length > 0) {
            await fetchList()
        }

        return movement
    }

    async function transfer(input: TransferStockInput): Promise<{ exit_movement: InventoryMovement; entry_movement: InventoryMovement }> {
        const toast = useToast()
        const result = await InventoryService.transfer(input)
        toast.success('Transferencia realizada')

        // Refresh both affected rows (from and to branch for same variant).
        const fromIdx = items.value.findIndex(
            (item) =>
                item.branch_id === input.from_branch_id &&
                item.product_variant_id === input.product_variant_id,
        )
        const toIdx = items.value.findIndex(
            (item) =>
                item.branch_id === input.to_branch_id &&
                item.product_variant_id === input.product_variant_id,
        )

        const refreshPromises: Promise<void>[] = []

        if (fromIdx !== -1) {
            refreshPromises.push(
                InventoryService.get(items.value[fromIdx].id).then((updated) => {
                    items.value[fromIdx] = updated
                }),
            )
        }
        if (toIdx !== -1) {
            refreshPromises.push(
                InventoryService.get(items.value[toIdx].id).then((updated) => {
                    items.value[toIdx] = updated
                }),
            )
        }
        if (refreshPromises.length === 0) {
            await fetchList()
        } else {
            await Promise.all(refreshPromises)
        }

        return result
    }

    async function fetchMovements(filters: MovementFilters = {}): Promise<void> {
        isLoading.value = true
        error.value = null
        try {
            const result = await InventoryService.listMovements(filters)
            movements.value = result.data
            movementsPagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error al cargar los movimientos'
            movements.value = []
        } finally {
            isLoading.value = false
        }
    }

    return {
        items,
        current,
        pagination,
        movements,
        movementsPagination,
        isLoading,
        error,
        fetchList,
        fetchOne,
        recordMovement,
        transfer,
        fetchMovements,
    }
})
