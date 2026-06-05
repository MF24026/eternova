<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useUiStore } from '@/stores/ui'
import LayoutSwitcher from '@/components/layout/LayoutSwitcher.vue'
import AppToast from '@/components/base/AppToast.vue'

const ui = useUiStore()

onMounted(() => {
    ui.initDarkMode()
})
</script>

<template>
    <LayoutSwitcher>
        <RouterView />
    </LayoutSwitcher>
    <AppToast />
</template>
