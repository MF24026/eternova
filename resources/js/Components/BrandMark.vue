<script setup>
import { Flower } from 'lucide-vue-next';

defineProps({
    size: { type: Number, default: 32 },
    showWordmark: { type: Boolean, default: true },
    primaryText: { type: String, default: 'Eternova' },
    secondaryText: { type: String, default: '' },
    wordmarkSize: { type: Number, default: 22 },
});
</script>

<template>
    <div style="display: flex; align-items: center; gap: 10px">
        <span
            :style="{
                width: `${size}px`,
                height: `${size}px`,
                borderRadius: '50%',
                background: 'var(--gradient)',
                display: 'grid',
                placeItems: 'center',
                color: 'var(--on-primary)',
                flexShrink: 0,
            }"
        >
            <Flower :size="Math.round(size * 0.55)"/>
        </span>
        <span
            v-if="showWordmark"
            class="serif"
            :style="{ fontSize: `${wordmarkSize}px`, letterSpacing: '-0.02em', lineHeight: 1.05 }"
        >
            {{ primaryText }}<br v-if="secondaryText"/>
            <span v-if="secondaryText" :style="{ color: 'var(--primary)', fontSize: `${Math.round(wordmarkSize * 0.7)}px` }">{{ secondaryText }}</span>
        </span>
    </div>
</template>
