<template>
    <n-select
        :value="store.current"
        :options="store.options"
        :loading="store.loading"
        :disabled="store.loading || !store.options.length"
        class="lang-select"
        @update:value="store.setLanguage"
    />
</template>

<script setup>
import { useLanguageStore } from '@/stores/language.js';

const store = useLanguageStore();
</script>

<style scoped lang="scss">
.lang-select {
    min-width: 115px;
}
</style>
