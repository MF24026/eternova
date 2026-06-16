<script setup lang="ts">
/**
 * AuthShell — the shared "bloom" chrome for the unauthenticated auth pages
 * (login, forgot-password, reset-password).
 *
 * Renders the gradient backdrop, the blurred petals, the centered card and the
 * Eternova brand header. Page-specific content goes in the default slot and is
 * styled with the global .auth-* classes (see app.css).
 */
import { Flower2 } from 'lucide-vue-next'
</script>

<template>
    <div class="auth-shell">
        <span class="auth-petal auth-petal--rose" aria-hidden="true" />
        <span class="auth-petal auth-petal--lilac" aria-hidden="true" />

        <div class="card auth-card fade-in">
            <div class="auth-brand">
                <span class="auth-brand-badge"><Flower2 :size="22" /></span>
                <span class="serif auth-brand-name">Eternova</span>
            </div>

            <slot />
        </div>
    </div>
</template>

<style scoped>
.auth-shell {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    position: relative;
    overflow: hidden;
    background: var(--gradient-bloom);
}

.auth-petal {
    position: absolute;
    border-radius: 50%;
    filter: blur(8px);
    pointer-events: none;
}

.auth-petal--rose {
    top: -120px;
    right: -140px;
    width: 460px;
    height: 460px;
    opacity: 0.55;
    background: radial-gradient(circle at 35% 35%, #f7c9d2 0%, #f3b6c4 45%, transparent 70%);
}

.auth-petal--lilac {
    bottom: -120px;
    left: -100px;
    width: 340px;
    height: 340px;
    opacity: 0.5;
    background: radial-gradient(circle at 40% 40%, #e3d2ff 0%, #d3bcff 45%, transparent 70%);
}

.auth-card {
    position: relative;
    z-index: 2;
    width: 100%;
    max-width: 440px;
    padding: 48px;
    background: var(--surface-lowest);
    box-shadow: var(--shadow-ambient);
}

.auth-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 32px;
}

.auth-brand-badge {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--gradient);
    color: var(--on-primary);
    display: grid;
    place-items: center;
    flex-shrink: 0;
}

.auth-brand-name {
    font-size: 22px;
    line-height: 1;
    color: var(--on-surface);
}

@media (max-width: 480px) {
    .auth-card {
        padding: 32px 24px;
    }
}
</style>
