<script setup lang="ts">
import { computed, type Component } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import AppSpinner from './AppSpinner.vue'

interface Props {
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit' | 'reset'
    icon?: Component
    iconPosition?: 'left' | 'right'
    /** When set, renders as <router-link> instead of <button> */
    to?: RouteLocationRaw
    /** External href — renders as <a> */
    href?: string
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'primary',
    size: 'md',
    loading: false,
    disabled: false,
    type: 'button',
    iconPosition: 'left',
})

const variantClasses: Record<NonNullable<Props['variant']>, string> = {
    primary: [
        'bg-primary text-on-primary',
        'hover:bg-primary-dim',
        'focus-visible:ring-primary/40',
        'dark:bg-primary-container dark:text-on-surface dark:hover:bg-primary/80',
    ].join(' '),
    secondary: [
        'bg-surface-high text-on-surface',
        'hover:bg-surface-highest',
        'focus-visible:ring-on-surface/20',
        'dark:bg-surface-mid dark:hover:bg-surface-high',
    ].join(' '),
    ghost: [
        'bg-transparent text-primary',
        'hover:bg-primary-container/30',
        'focus-visible:ring-primary/30',
        'dark:text-primary-container dark:hover:bg-primary/10',
    ].join(' '),
    danger: [
        'bg-error text-on-primary',
        'hover:opacity-90',
        'focus-visible:ring-error/40',
        'dark:bg-error-container dark:text-on-surface',
    ].join(' '),
}

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    sm: 'px-3.5 py-1.5 text-xs gap-1.5',
    md: 'px-5 py-2.5 text-sm gap-2',
    lg: 'px-7 py-3 text-base gap-2.5',
}

const iconSizes: Record<NonNullable<Props['size']>, number> = {
    sm: 14,
    md: 16,
    lg: 18,
}

const baseClasses = computed(() => [
    'inline-flex items-center justify-center',
    'rounded-full',
    'font-sans font-medium',
    'transition-colors duration-150',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1',
    'disabled:opacity-50 disabled:pointer-events-none',
    sizeClasses[props.size ?? 'md'],
    variantClasses[props.variant ?? 'primary'],
])

const isDisabled = computed(() => props.disabled || props.loading)
const resolvedIconSize = computed(() => iconSizes[props.size ?? 'md'])
</script>

<template>
    <!-- Router link variant -->
    <RouterLink
        v-if="to"
        :to="to"
        :class="baseClasses"
        :aria-disabled="isDisabled || undefined"
    >
        <component
            :is="icon"
            v-if="icon && iconPosition === 'left'"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
        <AppSpinner v-if="loading" size="sm" />
        <slot />
        <component
            :is="icon"
            v-if="icon && iconPosition === 'right'"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
    </RouterLink>

    <!-- External link variant -->
    <a
        v-else-if="href"
        :href="href"
        :class="baseClasses"
        target="_blank"
        rel="noopener noreferrer"
    >
        <component
            :is="icon"
            v-if="icon && iconPosition === 'left'"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
        <slot />
        <component
            :is="icon"
            v-if="icon && iconPosition === 'right'"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
    </a>

    <!-- Button variant (default) -->
    <button
        v-else
        :type="type"
        :disabled="isDisabled"
        :class="baseClasses"
    >
        <component
            :is="icon"
            v-if="icon && iconPosition === 'left' && !loading"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
        <AppSpinner v-if="loading" size="sm" />
        <slot />
        <component
            :is="icon"
            v-if="icon && iconPosition === 'right' && !loading"
            :size="resolvedIconSize"
            aria-hidden="true"
        />
    </button>
</template>
