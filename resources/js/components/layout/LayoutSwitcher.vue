<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { defineAsyncComponent } from 'vue'

type LayoutName = 'admin' | 'storefront' | 'marketing' | 'onboarding' | 'super-admin' | 'default'

const AdminLayout = defineAsyncComponent(() => import('./AdminLayout.vue'))
const StorefrontLayout = defineAsyncComponent(() => import('./StorefrontLayout.vue'))
const MarketingLayout = defineAsyncComponent(() => import('./MarketingLayout.vue'))
const OnboardingLayout = defineAsyncComponent(() => import('./OnboardingLayout.vue'))
const SuperAdminLayout = defineAsyncComponent(() => import('./SuperAdminLayout.vue'))

const route = useRoute()

const layout = computed<LayoutName>(() => {
    return (route.meta.layout as LayoutName | undefined) ?? 'default'
})
</script>

<template>
    <AdminLayout v-if="layout === 'admin'">
        <slot />
    </AdminLayout>

    <StorefrontLayout v-else-if="layout === 'storefront'">
        <slot />
    </StorefrontLayout>

    <MarketingLayout v-else-if="layout === 'marketing'">
        <slot />
    </MarketingLayout>

    <OnboardingLayout v-else-if="layout === 'onboarding'">
        <slot />
    </OnboardingLayout>

    <SuperAdminLayout v-else-if="layout === 'super-admin'">
        <slot />
    </SuperAdminLayout>

    <slot v-else />
</template>
