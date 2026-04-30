<template>
    <section class="home">

        <!-- Header-->
        <div class="header-page">
            <h1>parser-emails</h1>
            <n-button
                strong secondary type="info"
                :loading="loading || isRunning"
                :disabled="!searchQueryId || isRunning"
                @click="sendQuery"
            >Отправить</n-button>
        </div>

        <div class="custom-page-row">
            <!-- Business type -->
            <div class="custom-page-column">
                <!-- Select type Business -->
                <div class="box-select">
                    <n-form-item
                        label="Тип бизнеса"
                        :show-feedback="false"
                    >
                        <div class="select-with-counter">
                            <n-select
                                v-model:value="typeBusinessId"
                                :options="typeBusinessOptions"
                                :loading="optionsLoading"
                                filterable
                                clearable
                                placeholder="Выберите тип бизнеса"
                                class="type-business-select"
                            />
                        </div>
                    </n-form-item>
                    <!-- Количество элементов в select -->
                    <span class="select-counter">{{ typeBusinessOptions.length }}</span>
                </div>
            </div>

            <!-- Search query -->
            <div
                v-if="typeBusinessId && searchQueryChecked"
                class="custom-page-column"
            >
                <n-form-item
                    label="Поисковый запрос"
                    :show-feedback="false"
                >
                    <n-input
                        v-model:value="searchQueryText"
                        :disabled="hasSearchQuery || searchSaving"
                        placeholder="Текст запроса"
                        @keydown.enter="saveSearchQuery"
                    />
                    <n-button
                        v-if="!hasSearchQuery"
                        type="primary"
                        :loading="searchSaving"
                        :disabled="!searchQueryText.trim()"
                        @click="saveSearchQuery"
                    >
                        Сохранить
                    </n-button>
                </n-form-item>
            </div>
        </div>

    </section>
</template>

<script setup>

// ============================================================
// IMPORTS
// ============================================================
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import apiAxios from '@/utils/apiAxios.js';
import { useLanguageStore } from '@/stores/language.js';

const languageStore = useLanguageStore();

// ============================================================
// CONSTANTS
// ============================================================

// ключ sessionStorage для запомненного выбора типа бизнеса
const TYPE_BUSINESS_KEY = 'home.typeBusinessId';

// ============================================================
// STATE
// ============================================================

// общее состояние страницы
const loading = ref(false);

// идёт ли сейчас обработка очередей search/crawl на сервере
const isRunning = ref(false);

// Адаптивный polling статуса очередей.
// При активном парсинге опрашиваем часто (UI должен быстро отразить
// завершение); в idle — редко, чтобы не дёргать сервер раз в 3 сек впустую.
const POLL_RUNNING_MS = 3000;
const POLL_IDLE_MS = 30000;

// id таймера следующей проверки статуса очередей (setTimeout, не setInterval)
let jobStatusTimer = null;

// type business — select + options (инициализация из sessionStorage)
const storedTypeBusinessId = (() => {
    const raw = sessionStorage.getItem(TYPE_BUSINESS_KEY);
    if (raw === null || raw === '') return null;
    const num = Number(raw);
    return Number.isFinite(num) ? num : null;
})();

const typeBusinessId = ref(storedTypeBusinessId);
const typeBusinessOptions = ref([]);
const optionsLoading = ref(false);

// search query: одна запись на пару (typeBusiness + language).
// searchQueryId = id существующего SearchQuery в БД, или null если нет.
// searchQueryText = текст в input'е (readonly если есть, либо для ввода нового).
const searchQueryId = ref(null);
const searchQueryText = ref('');
const searchSaving = ref(false);

// Главный маяк состояния SearchQuery для текущей пары:
//   false — мы ещё не знаем, есть ли запись в БД (loading или ничего не выбрано);
//   true  — запрос к серверу завершился, состояние известно (запись есть либо нет).
// Колонка показывается только когда checked === true.
const searchQueryChecked = ref(false);

// true когда для текущей пары (typeBusiness + language) уже есть SearchQuery
// в БД → input disabled, кнопки "Сохранить" нет.
const hasSearchQuery = computed(() => searchQueryId.value !== null);

// ============================================================
// WATCHERS
// ============================================================

// сохраняем выбор type business в sessionStorage; null = удаляем ключ
watch(typeBusinessId, (val) => {
    if (val === null || val === undefined) {
        sessionStorage.removeItem(TYPE_BUSINESS_KEY);
    } else {
        sessionStorage.setItem(TYPE_BUSINESS_KEY, String(val));
    }
});

// при смене типа бизнеса перезагружаем SearchQuery для пары (lang + typeId)
watch(typeBusinessId, (val) => {
    searchQueryChecked.value = false;
    if (val) {
        loadSearchQuery(val);
    } else {
        resetSearchQueryState();
    }
});

// при смене языка — то же самое
watch(() => languageStore.current, (val, prev) => {
    if (val !== prev && typeBusinessId.value) {
        loadSearchQuery(typeBusinessId.value);
    }
});

// ============================================================
// SERVER METHODS
// ============================================================

// SERVER - отправляет парсить запрос с выбранными параметрами
async function sendQuery() {
    if (!searchQueryId.value || isRunning.value) return;

    loading.value = true;

    const params = {
        search_query_id: searchQueryId.value,
    }

    const response = await apiAxios.post('/query', params);

    console.log('response', response);

    loading.value = false;

    // сразу проверим статус — задача только что попала в очередь, кнопка
    // должна заблокироваться. После update'а isRunning перезапускаем
    // таймер, чтобы цепочка polling'а сразу перешла на быстрый интервал.
    await checkJobStatus();
    scheduleNextJobStatusCheck();
}

// SERVER - проверяет, идёт ли обработка задач в очередях search/crawl
async function checkJobStatus() {
    const response = await apiAxios.get('/jobs/status');
    if (response?.status === 'success') {
        isRunning.value = !!response?.data?.running;
    }
}

// Запланировать следующую проверку статуса очередей с задержкой,
// зависящей от текущего isRunning. Когда состояние меняется (например,
// после sendQuery — running ставит true), цепочка автоматически переходит
// на быстрый интервал.
function scheduleNextJobStatusCheck() {
    if (jobStatusTimer !== null) {
        clearTimeout(jobStatusTimer);
    }

    const delay = isRunning.value ? POLL_RUNNING_MS : POLL_IDLE_MS;

    jobStatusTimer = setTimeout(async () => {
        await checkJobStatus();
        scheduleNextJobStatusCheck();
    }, delay);
}

// SERVER - тихий ежедневный бэкап БД.
// На бэке идемпотентно по дате: если файл backup_YYYY-MM-DD.sql уже есть —
// сервер просто вернёт reason=already_exists, ничего не пересоздавая.
async function ensureDailyBackup() {
    try {
        await apiAxios.get('/backup/daily');
    } catch (_) {
        // фоновая операция — ошибки молча проглатываем,
        // чтобы не мешать загрузке главной страницы
    }
}

// SERVER - подгружает все типы бизнеса
async function loadTypeBusinesses() {
    optionsLoading.value = true;
    const response = await apiAxios.get('/type-businesses');

    console.log(response)

    if(response?.status === "success"){
        const {items} = response?.data
        typeBusinessOptions.value = items ?? [];

        // если восстановленный из sessionStorage id не нашёлся среди опций
        // (тип удалили в БД) — сбрасываем выбор, чтобы select не показывал пустоту
        if (
            typeBusinessId.value !== null &&
            !typeBusinessOptions.value.some((o) => o.value === typeBusinessId.value)
        ) {
            typeBusinessId.value = null;
        }
    }

    optionsLoading.value = false;
}

// SERVER - подгружает существующий SearchQuery для пары (typeBusiness + language).
// На паре может быть только одна запись; берём первую из массива (бэкенд возвращает list).
// Если записи нет — input остаётся пустым и доступным для ввода.
async function loadSearchQuery(typeBusinessIdValue) {
    if (!typeBusinessIdValue) return;

    searchQueryChecked.value = false;

    const response = await apiAxios.get('/search-queries', {
        type_business_id: typeBusinessIdValue,
        language_code: languageStore.current,
    });

    if (response?.status === 'success') {
        const { items } = response?.data;
        const first = (items ?? [])[0] ?? null;

        if (first) {
            searchQueryId.value = first.value;
            searchQueryText.value = first.label ?? first.text ?? '';
        } else {
            resetSearchQueryState();
        }
    }

    searchQueryChecked.value = true;
}

// SERVER - создать SearchQuery для текущей пары (typeBusiness + language).
// После успешного create фиксируем id+text — input становится disabled,
// кнопка "Сохранить" исчезает, можно сразу нажимать "Отправить".
async function saveSearchQuery() {
    const text = searchQueryText.value.trim();
    if (!text || searchSaving.value || !typeBusinessId.value || hasSearchQuery.value) return;

    searchSaving.value = true;

    const params = {
        text,
        type_business_id: typeBusinessId.value,
        language_code: languageStore.current,
    };

    const response = await apiAxios.post('/search-queries', params);

    if (response?.status === 'success' && response?.data?.item) {
        const item = response.data.item;
        searchQueryId.value = item.value;
        searchQueryText.value = item.label ?? item.text ?? text;
    }

    searchSaving.value = false;
}

// Сброс состояния SearchQuery: для случаев "тип бизнеса не выбран"
// или "для пары пока нет SearchQuery в БД".
function resetSearchQueryState() {
    searchQueryId.value = null;
    searchQueryText.value = '';
}

// ============================================================
// LIFECYCLE
// ============================================================

onMounted(async () => {
    // фоновый ежедневный бэкап БД (без await — не блокирует загрузку UI)
    ensureDailyBackup();

    await loadTypeBusinesses();

    // если type business восстановлен из sessionStorage — подгружаем
    // соответствующий SearchQuery (watch не сработает, т.к. значение не менялось)
    if (typeBusinessId.value) {
        await loadSearchQuery(typeBusinessId.value);
    }

    // проверяем статус очередей и запускаем адаптивный polling
    // (3 сек когда running, 30 сек когда idle)
    await checkJobStatus();
    scheduleNextJobStatusCheck();
});

onBeforeUnmount(() => {
    if (jobStatusTimer !== null) {
        clearTimeout(jobStatusTimer);
        jobStatusTimer = null;
    }
});
</script>

<style scoped lang="scss">
@use "../../scss/variables" as *;

.home {
    padding: 24px 0;

    .header-page{
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 40px;
        h1 {
            margin: 0;
        }
    }

    .custom-page-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;

        > * {
            min-width: 0;
        }
    }

    .custom-page-column {
        display: flex;
        flex-flow: column nowrap;

        .box-select{
            display: flex;
            align-items: flex-end;
            margin-bottom: 20px;

            .label-with-button {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                width: 100%;
            }
            .type-business-select {
                flex: 1 1 auto;
            }
            .select-with-counter {
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
            }
            .section-add-business{

            }
            .select-counter {
                min-width: 33px;
                height: 33px;
                border-radius: 12px;
                background: $color-muted;
                color: #fff;
                margin-left: 10px;
                font-size: 12px;
                display: flex;
                justify-content: center;
                align-items: center;
            }
        }


    }
}

:deep(.n-form-item) {
    flex: 1 1 auto;
}
:deep(.n-form-item-label) {
    width: 100%;
}
:deep(.n-select) {
    width: 100%;
}
:deep(.n-form-item-label__text) {
    width: 100%;
}
:deep(.n-form-item-blank) {
    display: flex;
    align-items: baseline;
    gap: 8px;
    min-height: 34px;
    input {
        flex: 1 1 auto;
    }
}

</style>
