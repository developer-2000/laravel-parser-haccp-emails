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
        // Кэш in-flight промиса load(). Нужен чтобы конкурентные вызовы
        // (App.vue parent + Home.vue child в одном тике) дождались одного
        // и того же запроса GET /languages, а не отвалились на guard'е сразу.
        _loadPromise: null,
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
         *
         * Идемпотентна и safe для конкурентных вызовов: если запрос уже летит,
         * повторный await дождётся того же промиса вместо того, чтобы отвалиться
         * на guard'е (иначе current=null уезжает в зависимые запросы).
         */
        load() {
            if (this.loaded) return Promise.resolve();
            if (this._loadPromise) return this._loadPromise;

            this._loadPromise = (async () => {
                this.loading = true;
                try {
                    const data = await apiAxios.get('/languages');
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
                } finally {
                    this.loading = false;
                    this._loadPromise = null;
                }
            })();

            return this._loadPromise;
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
