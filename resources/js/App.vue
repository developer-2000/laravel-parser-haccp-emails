<template>
    <div class="app-wrapper">
        <Header />
        <main class="container">
            <router-view />
        </main>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import Header from '@/components/Header.vue';
import { useLanguageStore } from '@/stores/language.js';

const langStore = useLanguageStore();

onMounted(() => {
    langStore.load();
});
</script>
