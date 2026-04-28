import { defineStore } from 'pinia';
import apiAxios from '@/utils/apiAxios.js';

const STORAGE_KEY = 'site_language';

/**
 * Глобальный стор текущего языка проекта.
 * Источник истины для всех компонентов: read через store.current, write через store.setLanguage(code).
 * Синхронизирует localStorage и серверную сессию (Laravel session).
 */
export const useLanguageStore = defineStore('language', {
    state: () => ({
        languages: [],
        current: null,
        loading: false,
        loaded: false,
    }),

    getters: {
        /**
         * Полный объект текущего языка (code + name) для UI.
         */
        currentLanguage: (state) =>
            state.languages.find((l) => l.code === state.current) || null,

        /**
         * Готовый список options для n-select.
         */
        options: (state) =>
            state.languages.map((l) => ({ label: l.name, value: l.code })),
    },

    actions: {
        /**
         * Грузит список языков с бэка один раз за сессию SPA.
         * Восстанавливает выбор из localStorage, при расхождении с серверной сессией —
         * подтягивает её под выбор пользователя.
         */
        async load() {
            if (this.loaded || this.loading) return;
            this.loading = true;

            const data = await apiAxios.get('/languages');
            this.loading = false;

            if (data?.status !== 'success') return;

            const { languages, current: serverCurrent } = data.data;

            this.languages = languages;

            const stored = localStorage.getItem(STORAGE_KEY);
            // Если сохраненный есть и он есть в массиве языков
            const storedIsValid = stored && languages.some((l) => l.code === stored);
            // Сохраненный или по умолчанию язык
            this.current = storedIsValid ? stored : serverCurrent;

            // Если сохраненный и по умолчанию языки разные
            if (storedIsValid && stored !== serverCurrent) {
                await this.persist(stored);
            }

            this.loaded = true;
        },

        /**
         * Меняет текущий язык: моментально обновляет state, затем синхронизирует
         * с localStorage и серверной сессией.
         */
        async setLanguage(code) {
            if (code === this.current) return;
            this.current = code;
            await this.persist(code);
        },

        /**
         * Записать язык localStorage в сессию.
         * Пользователь выбрал "de" → сохранилось в localStorage И в session('site_language').
         * Прошло время — laravel_session cookie истекла (по дефолту 120 минут) или пользователь почистил cookies, но localStorage остался.
         * Пользователь снова заходит → GET /languages.
         * Сервер не видит сессию → возвращает current: "en" (дефолт).
         * Фронт читает localStorage → видит "de".
         * UI ставит "de" как текущий.
         */
        async persist(code) {
            localStorage.setItem(STORAGE_KEY, code);
            await apiAxios.post('/language', { code });
        },
    },
});
