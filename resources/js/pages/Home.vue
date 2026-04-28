<template>
    <section class="home">
        <h1>parser-emails</h1>

        <n-button :loading="loading" @click="sendQuery">Отправить</n-button>

        <pre v-if="result">{{ result }}</pre>
    </section>
</template>

<script setup>
import { ref } from 'vue';
import apiAxios from '@/utils/apiAxios.js';

const loading = ref(false);
const result = ref(null);

async function sendQuery() {
    loading.value = true;
    // result.value
    const response = await apiAxios.post('/query', { id: 1 });

    console.log('response', response)

    loading.value = false;
}
</script>

<style scoped lang="scss">
@use "../../scss/variables" as *;

.home {
    padding: 24px 0;

    h1 {
        margin: 0 0 8px;
        color: $color-text;
    }

    p {
        color: $color-muted;
        margin-bottom: 16px;
    }
}
</style>
