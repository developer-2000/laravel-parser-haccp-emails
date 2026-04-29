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
            <div class="custom-page-column">
                <div class="box-select">
                    <!-- 1 Select Search query -->
                    <n-form-item
                        :show-feedback="false"
                    >
                        <!-- Label and Button (add)-->
                        <template #label>
                            <div class="label-with-button">
                                <span>Поисковый запрос</span>
                                <n-button
                                    v-if="!searchAddMode"
                                    size="tiny"
                                    strong secondary type="info"
                                    :disabled="!typeBusinessId"
                                    @click="openAddQuery"
                                >
                                    Добавить
                                </n-button>
                            </div>
                        </template>

                        <div class="select-with-counter">
                            <n-select
                                v-model:value="searchQueryId"
                                :options="searchQueryOptions"
                                :loading="searchOptionsLoading"
                                :disabled="!typeBusinessId"
                                filterable
                                clearable
                                placeholder="Выберите запрос"
                                class="type-business-select"
                            />
                        </div>
                    </n-form-item>
                    <!-- Количество элементов в select -->
                    <span class="select-counter">{{ searchQueryOptions.length }}</span>
                </div>

                <!-- 2 Input ADD Search query -->
                <n-form-item
                    v-if="searchAddMode"
                    :show-feedback="false"
                    label="Новый запрос"
                    class="section-add-business"
                >
                    <n-input
                        v-model:value="searchNewName"
                        placeholder="Текст запроса"
                        :disabled="searchSaving"
                        @keydown.enter="saveNewQuery"
                    />
                    <n-button
                        :disabled="searchSaving"
                        @click="cancelAddQuery"
                    >
                        Отмена
                    </n-button>
                    <n-button
                        type="primary"
                        :loading="searchSaving"
                        :disabled="!searchNewName.trim()"
                        @click="saveNewQuery"
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
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import apiAxios from '@/utils/apiAxios.js';
import { useLanguageStore } from '@/stores/language.js';

const languageStore = useLanguageStore();

// ============================================================
// CONSTANTS
// ============================================================

// ключ sessionStorage для запомненного выбора типа бизнеса
const TYPE_BUSINESS_KEY = 'home.typeBusinessId';

// контекстный ключ sessionStorage для выбора запроса:
// у каждой пары (язык + тип бизнеса) свой запомненный выбор —
// при возврате к этой паре select восстанавливается.
function searchQueryKey(languageCode, typeBusinessIdValue) {
    return `home.searchQueryId.${languageCode}.${typeBusinessIdValue}`;
}

// ============================================================
// STATE
// ============================================================

// общее состояние страницы
const loading = ref(false);

// идёт ли сейчас обработка очередей search/crawl на сервере
const isRunning = ref(false);

// id таймера опроса статуса очередей
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

// search query — select + options.
// Стартовое значение = null, после загрузки опций восстанавливаем
// сохранённое значение из sessionStorage по ключу (lang + typeId).
const searchQueryId = ref(null);
const searchQueryOptions = ref([]);
const searchOptionsLoading = ref(false);

// search query — форма «Добавить»
const searchAddMode = ref(false);
const searchNewName = ref('');
const searchSaving = ref(false);

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

// при смене типа бизнеса перезагружаем список запросов и пробуем
// восстановить выбор для новой пары (lang + typeId)
watch(typeBusinessId, (val) => {
    if (val) {
        loadSearchQueries(val);
    } else {
        searchQueryOptions.value = [];
        searchQueryId.value = null;
    }
});

// при смене языка — то же самое
watch(() => languageStore.current, (val, prev) => {
    if (val !== prev && typeBusinessId.value) {
        loadSearchQueries(typeBusinessId.value);
    }
});

// сохраняем выбор search query по контекстному ключу (lang + typeId).
// null = удаляем ключ для текущей пары.
watch(searchQueryId, (val) => {
    const lang = languageStore.current;
    const typeId = typeBusinessId.value;
    if (!lang || !typeId) return;

    const key = searchQueryKey(lang, typeId);
    if (val === null || val === undefined) {
        sessionStorage.removeItem(key);
    } else {
        sessionStorage.setItem(key, String(val));
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

    // сразу проверим статус — задача только что попала в очередь, кнопка должна заблокироваться
    checkJobStatus();
}

// SERVER - проверяет, идёт ли обработка задач в очередях search/crawl
async function checkJobStatus() {
    const response = await apiAxios.get('/jobs/status');
    if (response?.status === 'success') {
        isRunning.value = !!response?.data?.running;
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

// SERVER - подгружает поисковые запросы для выбранного типа бизнеса
async function loadSearchQueries(typeBusinessIdValue) {
    if (!typeBusinessIdValue) return;

    const lang = languageStore.current;

    searchOptionsLoading.value = true;
    const response = await apiAxios.get('/search-queries', {
        type_business_id: typeBusinessIdValue,
        language_code: lang,
    });

    if (response?.status === 'success') {
        const { items } = response?.data;
        searchQueryOptions.value = items ?? [];

        // восстанавливаем сохранённый выбор для пары (lang + typeId).
        // если ключа нет, или saved id не в options (запись удалили) — null.
        const raw = sessionStorage.getItem(searchQueryKey(lang, typeBusinessIdValue));
        const savedId = raw !== null && raw !== '' && Number.isFinite(Number(raw))
            ? Number(raw)
            : null;

        searchQueryId.value = savedId !== null
            && searchQueryOptions.value.some((o) => o.value === savedId)
            ? savedId
            : null;
    }

    searchOptionsLoading.value = false;
}

// SERVER - Создать новый поисковый запрос
async function saveNewQuery() {
    const text = searchNewName.value.trim();
    if (!text || searchSaving.value || !typeBusinessId.value) return;

    searchSaving.value = true;

    const params = {
        text,
        type_business_id: typeBusinessId.value,
        language_code: languageStore.current,
    }

    const response = await apiAxios.post('/search-queries', params);

    if (response?.status === 'success' && response?.data?.item) {
        const item = response.data.item;
        searchQueryOptions.value = [...searchQueryOptions.value, item];
        searchQueryId.value = item.value;
        cancelAddQuery();
    }

    searchSaving.value = false;
}

// ============================================================
// UI METHODS
// ============================================================

function openAddQuery() {
    searchAddMode.value = true;
    searchNewName.value = '';
}

function cancelAddQuery() {
    searchAddMode.value = false;
    searchNewName.value = '';
}

// ============================================================
// LIFECYCLE
// ============================================================

onMounted(async () => {
    await loadTypeBusinesses();

    // если type business восстановлен из sessionStorage — подгружаем
    // соответствующий список запросов (watch не сработает, т.к. значение не менялось)
    if (typeBusinessId.value) {
        await loadSearchQueries(typeBusinessId.value);
    }

    // проверяем статус очередей и запускаем периодический опрос (раз в 3 секунды)
    await checkJobStatus();
    jobStatusTimer = setInterval(checkJobStatus, 3000);
});

onBeforeUnmount(() => {
    if (jobStatusTimer) {
        clearInterval(jobStatusTimer);
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
        display: flex;
        flex-flow: row nowrap;
        gap: 24px;

        // все дочерние первого уровня — одинаковой ширины (flex-basis: 0 + grow: 1)
        > * {
            flex: 1 1 0;
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
    align-items: center;
    gap: 8px;
    input{
        flex: 1 1 auto;
    }
}

</style>
