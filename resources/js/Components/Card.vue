<script setup>
const props = defineProps({
    hover: Boolean,
    interactive: Boolean,
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <div
        class="card"
        :class="{
            'card-hover': hover,
            'card-interactive': interactive,
        }"
        :style="padded ? null : { padding: 0 }"
    >
        <slot/>
    </div>
</template>
