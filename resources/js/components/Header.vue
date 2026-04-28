<template>
    <header class="header">
        <nav class="nav container" role="navigation">
            <div class="nav-logo">
                <router-link :to="{ name: 'home' }" class="logo-link">
                    <span class="logo-site">Parser</span>
                </router-link>
            </div>
            <div class="nav-actions">
                <LanguageSwitcher />
            </div>
        </nav>
    </header>
</template>

<script setup>
import LanguageSwitcher from './LanguageSwitcher.vue';
</script>

<style scoped lang="scss">
@use "../../scss/variables" as *;

.header {
    background: $color-bg;
    color: $color-text;
    border-bottom: 1px solid $color-border;
}
.nav {
    height: $header-height;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.nav-logo {
    display: flex;
    align-items: center;
}
.nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.logo-link {
    display: inline-flex;
    align-items: center;
    text-decoration: none;
    color: inherit;

    &:hover .logo-site {
        color: $color-primary;
    }
}
.logo-site {
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 0.5px;
    line-height: 1;
    transition: color .15s ease;
}
</style>
