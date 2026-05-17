<script setup>
// Re-export Bloom as Badge for backwards compatibility.
import Bloom from './Bloom.vue';
defineOptions({ inheritAttrs: false });
</script>

<template>
    <Bloom v-bind="$attrs"><slot/></Bloom>
</template>
