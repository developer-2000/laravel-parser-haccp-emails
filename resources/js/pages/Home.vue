<template>
    <section class="home">

        <!-- Header-->
        <div class="header-page">
            <h1>parser-emails</h1>
            <div class="box-header-button">
                <n-popconfirm
                    positive-text="Отправить"
                    negative-text="Отмена"
                    :positive-button-props="{ type: 'info' }"
                    @positive-click="sendQuery"
                >
                    <template #trigger>
                        <n-button
                            strong secondary type="info"
                            :loading="loading || isRunning"
                            :disabled="!searchQueryId || isRunning"
                        >Отправить</n-button>
                    </template>
                    Запустить парсинг для выбранного запроса?
                </n-popconfirm>
                <!-- Тогглер видимости мягко удалённых: показывается только текущая иконка.
                     По умолчанию showDeleted=false → eye-slash (скрытые не видны).
                     Клик переключает на eye-open (скрытые видны) и наоборот. -->
                <n-button
                    strong
                    secondary
                    @click="showDeleted = !showDeleted"
                >
                    <svg v-if="!showDeleted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path d="M320 400c-75.85 0-137.25-58.71-142.9-133.11L72.2 185.82c-13.79 17.3-26.48 35.59-36.72 55.59a32.35 32.35 0 0 0 0 29.19C89.71 376.41 197.07 448 320 448c26.91 0 52.87-4 77.89-10.46L346 397.39a144.13 144.13 0 0 1-26 2.61zm313.82 58.1l-110.55-85.44a331.25 331.25 0 0 0 81.25-102.07a32.35 32.35 0 0 0 0-29.19C550.29 135.59 442.93 64 320 64a308.15 308.15 0 0 0-147.32 37.7L45.46 3.37A16 16 0 0 0 23 6.18L3.37 31.45A16 16 0 0 0 6.18 53.9l588.36 454.73a16 16 0 0 0 22.46-2.81l19.64-25.27a16 16 0 0 0-2.82-22.45zm-183.72-142l-39.3-30.38A94.75 94.75 0 0 0 416 256a94.76 94.76 0 0 0-121.31-92.21A47.65 47.65 0 0 1 304 192a46.64 46.64 0 0 1-1.54 10l-73.61-56.89A142.31 142.31 0 0 1 320 112a143.92 143.92 0 0 1 144 144c0 21.63-5.29 41.79-13.9 60.11z" fill="currentColor"></path></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"
                    class="svg-eye"
                    ><path d="M572.52 241.4C518.29 135.59 410.93 64 288 64S57.68 135.64 3.48 241.41a32.35 32.35 0 0 0 0 29.19C57.71 376.41 165.07 448 288 448s230.32-71.64 284.52-177.41a32.35 32.35 0 0 0 0-29.19zM288 400a144 144 0 1 1 144-144a143.93 143.93 0 0 1-144 144zm0-240a95.31 95.31 0 0 0-25.31 3.79a47.85 47.85 0 0 1-66.9 66.9A95.78 95.78 0 1 0 288 160z" fill="currentColor"></path></svg>
                </n-button>
            </div>

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
                        :disabled="(hasSearchQuery && !searchEditing) || searchSaving"
                        placeholder="Текст запроса"
                        @keydown.enter="onSearchQueryEnter"
                    />
                    <!-- Создание новой записи -->
                    <n-button
                        v-if="!hasSearchQuery"
                        type="primary"
                        :loading="searchSaving"
                        :disabled="!searchQueryText.trim()"
                        @click="saveSearchQuery"
                    >
                        Сохранить
                    </n-button>
                    <!-- Редактирование существующей: либо кнопка "карандаш",
                         либо в режиме редактирования "Сохранить". -->
                    <n-button
                        v-else-if="!searchEditing"
                        quaternary
                        class="search-query-edit-btn"
                        @click="enterSearchEdit"
                    >
                        <svg width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3"/>
                                <path d="M9 15h3l8.5-8.5a1.5 1.5 0 0 0-3-3L9 12v3"/>
                                <path d="M16 5l3 3"/>
                            </g>
                        </svg>
                    </n-button>
                    <n-button
                        v-else
                        type="primary"
                        :loading="searchSaving"
                        :disabled="!searchQueryText.trim()"
                        @click="updateSearchQuery"
                    >
                        Сохранить
                    </n-button>
                </n-form-item>
            </div>
        </div>

        <!-- Companies table — показывается только если есть записи -->
        <div v-if="companies.length" class="companies-table">
            <n-data-table
                :columns="companyColumns"
                :data="companies"
                :bordered="true"
                :single-line="false"
                size="small"
                :row-key="(row) => row.id"
                :pagination="pagination"
            />
        </div>

        <!-- Отправить Letter на этот Email -->
        <n-modal
            v-model:show="showEmailModal"
            preset="card"
            content-scrollable
            :style="{ width: '600px', height: '400px' }"
            :segmented="{ content: true, footer: true }"
        >
            <template #header>
                {{ editingEmail }}
            </template>

            <div class="email-modal-body">
                <!-- История писем для этой пары (company_id + email).
                     По умолчанию все панели свернуты (default-expanded-names не задан).
                     Заголовок панели — сокращённый превью текста письма,
                     внутри — полный текст. -->
                <n-collapse
                    v-if="letterHistory.length"
                    class="email-modal-body__history"
                    accordion
                >
                    <n-collapse-item
                        v-for="item in letterHistory"
                        :key="item.id"
                        :name="String(item.id)"
                        :title="letterPreview(item.letter)"
                    >
                        <div class="email-modal-body__letter">{{ item.letter }}</div>
                    </n-collapse-item>
                </n-collapse>

                <n-input
                    v-model:value="editingLetter"
                    type="textarea"
                    placeholder="Текст письма"
                    :autosize="{ minRows: 8 }"
                />
            </div>

            <template #footer>
                <n-button
                    type="info"
                    strong
                    secondary
                    :loading="emailSending"
                    :disabled="!editingLetter.trim() || emailSending"
                    @click="sendEmailLetter"
                >
                    Отправить
                </n-button>
            </template>
        </n-modal>

        <!-- Редактирование компании (поле name) -->
        <n-modal
            v-model:show="showCompanyModal"
            preset="card"
            :style="{ width: '500px' }"
            :segmented="{ content: true, footer: true }"
        >
            <template #header>
                Редактировать компанию #{{ editingCompanyForName?.id }}
            </template>

            <n-form-item label="Название" :show-feedback="false">
                <n-input
                    v-model:value="editingCompanyName"
                    placeholder="Название компании"
                    @keydown.enter="saveCompanyName"
                />
            </n-form-item>

            <template #footer>
                <n-button
                    type="info"
                    strong
                    secondary
                    :loading="companySaving"
                    :disabled="!editingCompanyName.trim() || companySaving"
                    @click="saveCompanyName"
                >
                    Сохранить
                </n-button>
            </template>
        </n-modal>

    </section>
</template>

<script setup>

// ============================================================
// IMPORTS
// ============================================================
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, h } from 'vue';
import { NButton, NPopconfirm } from 'naive-ui';
import apiAxios from '@/utils/apiAxios.js';
import { notification } from '@/utils/notify.js';
import { useLanguageStore } from '@/stores/language.js';

const languageStore = useLanguageStore();

// ============================================================
// CONSTANTS
// ============================================================

// ключ sessionStorage для запомненного выбора типа бизнеса
const TYPE_BUSINESS_KEY = 'home.typeBusinessId';

// ключ localStorage для запомненного pageSize в пагинации таблицы компаний.
// localStorage (а не sessionStorage), чтобы выбор пережил перезагрузку
// и закрытие вкладки — это долгоживущее UX-предпочтение.
const PAGE_SIZE_KEY = 'home.companies.pageSize';
const PAGE_SIZES = [10, 20, 50, 100];

// Восстанавливаем pageSize из localStorage. Защита от мусора:
// — невалидное число → дефолт 10;
// — значение, которого нет в PAGE_SIZES → дефолт 10
//   (иначе селектор покажет пустоту).
function readStoredPageSize() {
    const raw = localStorage.getItem(PAGE_SIZE_KEY);
    const num = Number(raw);
    return Number.isFinite(num) && PAGE_SIZES.includes(num) ? num : 10;
}

// ============================================================
// STATE
// ============================================================

// общее состояние страницы
const loading = ref(false);

// Список компаний для текущего SearchQuery (после полного парсинга).
// Пустой массив = таблица скрыта, иначе отображается n-data-table.
// Подгружается при наличии searchQueryId через watcher ниже.
const companies = ref([]);

// Показывать ли мягко удалённые компании (записи с deleted_at != null).
// false (по умолчанию) — таблица скрывает удалённые;
// true — бэкенд возвращает их тоже (через with_trashed).
const showDeleted = ref(false);

// Пагинация для n-data-table. Реактивный объект — n-data-table сам
// читает/обновляет page и pageSize через v-model-подобный механизм.
// show-size-picker рендерит селектор справа от пагинации в одной строке;
// центрирование всей группы — через :deep CSS ниже.
const pagination = reactive({
    page: 1,
    pageSize: readStoredPageSize(),
    showSizePicker: true,
    pageSizes: PAGE_SIZES,
    onChange: (page) => {
        pagination.page = page;
    },
    onUpdatePageSize: (pageSize) => {
        pagination.pageSize = pageSize;
        pagination.page = 1;
        // Запоминаем выбор, чтобы пережил перезагрузку страницы.
        localStorage.setItem(PAGE_SIZE_KEY, String(pageSize));
    },
});

// Модалка редактирования письма для email компании.
// editingEmail/editingCompanyId — контекст (какой email какой компании
// редактируется); editingLetter — текст письма в textarea;
// emailSending — флаг "идёт сохранение", блокирует кнопку отправки.
const showEmailModal = ref(false);
const editingEmail = ref('');
const editingCompanyId = ref(null);
const editingLetter = ref('');
const emailSending = ref(false);

// История писем для текущей пары (company_id + email) — массив
// { id, letter, created_at }, в обратном порядке (новые сверху).
// Подгружается при открытии модалки.
const letterHistory = ref([]);

// Модалка редактирования компании (сейчас — только поле name).
// editingCompanyForName хранит ссылку на саму row в companies — после
// успешного сохранения мы её мутируем, и таблица сразу перерисовывается.
const showCompanyModal = ref(false);
const editingCompanyForName = ref(null);
const editingCompanyName = ref('');
const companySaving = ref(false);

// Сокращённый превью текста письма для заголовка гармошки.
// Берём первую непустую строку, обрезаем до 60 символов с многоточием.
function letterPreview(letter) {
    const first = String(letter ?? '').split('\n').find((l) => l.trim()) ?? '';
    return first.length > 60 ? first.slice(0, 60).trimEnd() + '…' : first;
}

async function openEmailEdit(companyId, email) {
    editingCompanyId.value = companyId;
    editingEmail.value = email;
    editingLetter.value = '';
    letterHistory.value = [];
    showEmailModal.value = true;

    // Подгружаем историю писем для гармошки. Ошибки молча проглатываем —
    // apiAxios сам покажет toast, а пустая история не блокирует ввод нового.
    const response = await apiAxios.get('/company-emails', {
        company_id: companyId,
        email,
    });
    if (response?.status === 'success') {
        letterHistory.value = response?.data?.items ?? [];
    }
}

// VNode-фабрика иконки "копировать" (две накладывающиеся карточки, контурный
// вариант). Дети обёрнуты в <g fill="none" stroke="currentColor"> по той же
// причине, что и в pencilIconVNode: иначе fill/stroke на дочерних path/rect
// при рендере через h() могут потеряться, и path заливается чёрным.
function copyIconVNode() {
    return h(
        'svg',
        {
            width: 16,
            height: 16,
            xmlns: 'http://www.w3.org/2000/svg',
            viewBox: '0 0 512 512',
        },
        h(
            'g',
            {
                fill: 'none',
                stroke: 'currentColor',
                'stroke-width': '32',
                'stroke-linejoin': 'round',
            },
            [
                h('rect', {
                    x: 128,
                    y: 128,
                    width: 336,
                    height: 336,
                    rx: 57,
                    ry: 57,
                }),
                h('path', {
                    d: 'M383.5 128l.5-24a56.16 56.16 0 0 0-56-56H112a64.19 64.19 0 0 0-64 64v216a56.16 56.16 0 0 0 56 56h24',
                    'stroke-linecap': 'round',
                }),
            ],
        ),
    );
}

// Копирует JSON-снимок компании в системный буфер обмена и показывает
// уведомление об успехе. Поля: name, url, emails, tier — порядок и состав
// фиксированы, чтобы внешние потребители получали стабильную структуру.
async function copyCompanyToClipboard(row) {
    const payload = {
        name: row.name,
        url: row.url,
        emails: Array.isArray(row.emails) ? row.emails : [],
        tier: row.tier ?? null,
    };
    await navigator.clipboard.writeText(JSON.stringify(payload, null, 2));
    notification.success({
        content: 'Скопировано в память',
        duration: 3000,
        keepAliveOnHover: true,
    });
}

// VNode-фабрика иконки "письмо отправлено" (бумажный самолёт + часы).
// Показываем рядом с email'ом, если по нему уже есть запись в company_emails.
function sentLetterIconVNode() {
    return h(
        'svg',
        {
            width: 14,
            height: 14,
            xmlns: 'http://www.w3.org/2000/svg',
            viewBox: '0 0 24 24',
            class: 'email-row__sent-icon',
        },
        [
            h('path', {
                fill: 'currentColor',
                d: 'M17 10c.1 0 .19.01.28.01L3 4v6l8 2l-8 2v6l7-2.95V17c0-3.86 3.14-7 7-7z',
            }),
            h('path', {
                fill: 'currentColor',
                d: 'M17 12c-2.76 0-5 2.24-5 5s2.24 5 5 5s5-2.24 5-5s-2.24-5-5-5zm1.65 7.35L16.5 17.2V14h1v2.79l1.85 1.85l-.7.71z',
            }),
        ],
    );
}

// VNode-фабрика иконки-карандаша (используется и в action-колонке, и в
// каждой строке email). Атрибуты SVG в kebab-case, пути обязаны быть внутри
// <g>, иначе stroke/fill из <g> не применятся.
function pencilIconVNode() {
    return h(
        'svg',
        {
            width: 16,
            height: 16,
            xmlns: 'http://www.w3.org/2000/svg',
            viewBox: '0 0 24 24',
        },
        h(
            'g',
            {
                fill: 'none',
                stroke: 'currentColor',
                'stroke-width': '2',
                'stroke-linecap': 'round',
                'stroke-linejoin': 'round',
            },
            [
                h('path', { d: 'M9 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3' }),
                h('path', { d: 'M9 15h3l8.5-8.5a1.5 1.5 0 0 0-3-3L9 12v3' }),
                h('path', { d: 'M16 5l3 3' }),
            ],
        ),
    );
}

// Колонки n-data-table. Ширина задаётся через `width` (фиксированная px)
// или `minWidth` (минимум, дальше колонка может растягиваться). Колонка
// "Название" с minWidth — единственная резиновая, остальные фиксированные.
const companyColumns = [
    { title: 'Id', key: 'id', width: 70 },
    { title: 'Url', key: 'url', width: 320,
        render: (row) => h(
            'a',
            { href: row.url, target: '_blank', rel: 'noopener noreferrer' },
            row.url,
        ),
    },
    { title: 'Title', key: 'name', minWidth: 240 },
    { title: 'Email', key: 'emails', width: 320,
        // Каждый email — отдельная обёртка-flex: слева текст адреса
        // (с иконкой "уже отправлено", если есть запись в company_emails),
        // справа кнопка "редактировать" (карандаш). Клик открывает модалку
        // редактирования через openEmailEdit(companyId, email).
        render: (row) => {
            if (!Array.isArray(row.emails) || !row.emails.length) return '';
            const sent = new Set(Array.isArray(row.sent_emails) ? row.sent_emails : []);
            return row.emails.map((email) => h(
                'div',
                { class: 'email-row' },
                [
                    h('span', { class: 'email-row__text' }, [
                        email,
                        sent.has(email) ? sentLetterIconVNode() : null,
                    ]),
                    h(
                        NButton,
                        {
                            strong: true,
                            secondary: true,
                            size: 'tiny',
                            class: 'email-row__edit-btn',
                            onClick: () => openEmailEdit(row.id, email),
                        },
                        { default: () => pencilIconVNode() },
                    ),
                ],
            ));
        },
    },
    { title: 'Action', key: 'action', width: 160,
        render: (row) => h(
            'div',
            {
                style: 'display:flex; gap:8px;',
            },
            [

                // COPY BUTTON — копирует JSON компании в буфер обмена.
                h(
                    NButton,
                    {
                        quaternary: true,
                        class: 'company-copy-btn',
                        onClick: () => copyCompanyToClipboard(row),
                    },
                    { default: () => copyIconVNode() },
                ),

                // EDIT BUTTON
                h(
                    NButton,
                    {
                        quaternary: true,
                        class: 'company-edit-btn',
                        onClick: () => editCompany(row.id),
                    },
                    { default: () => pencilIconVNode() },
                ),

                // DELETE BUTTON
                h(
                    NPopconfirm,
                    {
                        positiveText: 'Удалить',
                        negativeText: 'Отмена',
                        positiveButtonProps: { type: 'error' },
                        onPositiveClick: () => deleteCompany(row.id),
                    },
                    {
                        trigger: () => h(NButton, { quaternary: true, class: 'company-delete-btn' }, {
                            default: () => h(
                                'svg',
                                {
                                    xmlns: 'http://www.w3.org/2000/svg',
                                    viewBox: '0 0 448 512',
                                    width: 16,
                                    height: 16,
                                },
                                h('path', {
                                    fill: 'currentColor',
                                    d: 'M268 416h24a12 12 0 0 0 12-12V188a12 12 0 0 0-12-12h-24a12 12 0 0 0-12 12v216a12 12 0 0 0 12 12zM432 80h-82.41l-34-56.7A48 48 0 0 0 274.41 0H173.59a48 48 0 0 0-41.16 23.3L98.41 80H16A16 16 0 0 0 0 96v16a16 16 0 0 0 16 16h16v336a48 48 0 0 0 48 48h288a48 48 0 0 0 48-48V128h16a16 16 0 0 0 16-16V96a16 16 0 0 0-16-16zM171.84 50.91A6 6 0 0 1 177 48h94a6 6 0 0 1 5.15 2.91L293.61 80H154.39zM368 464H80V128h288zm-212-48h24a12 12 0 0 0 12-12V188a12 12 0 0 0-12-12h-24a12 12 0 0 0-12 12v216a12 12 0 0 0 12 12z',
                                }),
                            ),
                        }),
                        default: () => `Удалить компанию ${row.url}?`,
                    },
                ),
            ],
        ),
    },
];

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

// Режим редактирования существующего SearchQuery (input разблокирован,
// рядом — кнопка "Сохранить" вместо "карандаша"). При смене типа бизнеса /
// языка состояние сбрасывается в watcher'ах ниже.
const searchEditing = ref(false);

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

// При появлении/смене searchQueryId — подтягиваем список компаний.
// null/undefined означает что для текущей пары (lang+type) нет SearchQuery →
// таблица скрыта (loadCompanies сам выставит companies = []).
watch(searchQueryId, (val) => {
    loadCompanies(val);
});

// Переключатель "видны/скрыты удалённые" → перезапрос списка с/без with_trashed.
watch(showDeleted, () => {
    loadCompanies(searchQueryId.value);
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

    // Гарантируем что languageStore.current уже выставлен. Без этого ловится
    // race с App.vue: child onMounted выполняется раньше parent, и
    // languageStore.current === null на момент первого вызова → axios
    // дропает null-параметр → backend возвращает 422 "language_code is required".
    // load() идемпотентна (loaded/loading-guard внутри), повторный await дешёв.
    await languageStore.load();

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
            // При смене пары начинаем в режиме просмотра, не редактирования —
            // даже если был открыт edit на предыдущем запросе.
            searchEditing.value = false;
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

// Включить режим редактирования существующего SearchQuery (карандашная
// кнопка справа от input'а). Input разблокируется, кнопка превращается
// в "Сохранить" → updateSearchQuery.
function enterSearchEdit() {
    searchEditing.value = true;
}

// SERVER - PUT /search-queries/{id} с новым text.
async function updateSearchQuery() {
    const text = searchQueryText.value.trim();
    if (!text || searchSaving.value || !searchQueryId.value) return;

    searchSaving.value = true;

    const response = await apiAxios.put(`/search-queries/${searchQueryId.value}`, { text });

    if (response?.status === 'success') {
        notification.success({
            content: response.message,
            duration: 3000,
            keepAliveOnHover: true,
        });
        const item = response?.data?.item;
        if (item) {
            searchQueryText.value = item.label ?? text;
        }
        searchEditing.value = false;
    }

    searchSaving.value = false;
}

// Enter в input'е — в зависимости от режима либо создаёт новую запись,
// либо обновляет существующую (если включён режим редактирования).
function onSearchQueryEnter() {
    if (hasSearchQuery.value) {
        if (searchEditing.value) updateSearchQuery();
    } else {
        saveSearchQuery();
    }
}

// Сброс состояния SearchQuery: для случаев "тип бизнеса не выбран"
// или "для пары пока нет SearchQuery в БД".
function resetSearchQueryState() {
    searchQueryId.value = null;
    searchQueryText.value = '';
    searchEditing.value = false;
}

// SERVER - подгружает список компаний для конкретного SearchQuery.
// Если бэкенд возвращает пустой массив — на фронте таблица скрыта
// (через v-if="companies.length" в шаблоне).
async function loadCompanies(searchQueryIdValue) {
    if (!searchQueryIdValue) {
        companies.value = [];
        return;
    }

    const response = await apiAxios.get('/companies', {
        search_query_id: searchQueryIdValue,
        with_trashed: showDeleted.value ? 1 : 0,
    });

    if (response?.status === 'success') {
        companies.value = response?.data?.items ?? [];
    } else {
        companies.value = [];
    }
}

// Мягкое удаление: бэкенд проставляет deleted_at, запись остаётся в БД.
// На фронте просто убираем строку из локального списка — таблица сама
// перерисуется (а если companies опустеет, исчезнет по v-if).
async function deleteCompany(id) {
    const response = await apiAxios.destroy(`/companies/${id}`);
    if (response?.status === 'success') {
        companies.value = companies.value.filter((c) => c.id !== id);
    }
}

// SERVER - сохраняет письмо для пары (company_id + email) в таблицу
async function sendEmailLetter() {
    const letter = editingLetter.value.trim();
    if (!letter || emailSending.value || !editingCompanyId.value) return;

    emailSending.value = true;

    const params = {
        company_id: editingCompanyId.value,
        email: editingEmail.value,
        letter,
    };

    const response = await apiAxios.post('/company-emails', params);

    if (response?.status === 'success') {
        notification.success({
            content: response.message,
            duration: 5000,
            keepAliveOnHover: true,
        });
        // Локально отметим email как "уже отправленный" — иконка появится
        // сразу, без перезагрузки списка компаний.
        const row = companies.value.find((c) => c.id === editingCompanyId.value);
        if (row) {
            const sent = Array.isArray(row.sent_emails) ? row.sent_emails : [];
            if (!sent.includes(editingEmail.value)) {
                row.sent_emails = [...sent, editingEmail.value];
            }
        }
        // Добавляем только что отправленное письмо в начало истории.
        // Бэкенд не возвращает item — собираем синтетический локально,
        // чтобы гармошка обновилась без повторного запроса. id отрицательный
        // (Date.now() со знаком минус), чтобы не конфликтовал с реальными.
        letterHistory.value = [
            {
                id: -Date.now(),
                letter,
                created_at: new Date().toISOString(),
            },
            ...letterHistory.value,
        ];
        showEmailModal.value = false;
    }

    emailSending.value = false;
}

// Открывает модалку редактирования компании. Берём актуальную row из
// companies — так и текущее name подставится в input, и при сохранении
// сможем мутировать ровно тот же объект (таблица обновится).
function editCompany(id) {
    const row = companies.value.find((c) => c.id === id);
    if (!row) return;

    editingCompanyForName.value = row;
    editingCompanyName.value = row.name ?? '';
    showCompanyModal.value = true;
}

// SERVER - PUT /companies/{id} с новым name. На успехе локально обновляем
// row, чтобы таблица отразила изменение без перезапроса всего списка.
async function saveCompanyName() {
    const row = editingCompanyForName.value;
    const name = editingCompanyName.value.trim();
    if (!row || !name || companySaving.value) return;

    companySaving.value = true;

    const response = await apiAxios.put(`/companies/${row.id}`, { name });

    if (response?.status === 'success') {
        notification.success({
            content: response.message,
            duration: 5000,
            keepAliveOnHover: true,
        });
        row.name = response?.data?.item?.name ?? name;
        showCompanyModal.value = false;
    }

    companySaving.value = false;
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
        .box-header-button{
            display: flex;
            align-items: center;
            gap: 8px;
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

    .companies-table {
        margin-top: 32px;
        :deep(.n-data-table__pagination) {
            justify-content: center;
            margin-top: 35px;
        }

        // Каждая строка email — flex-обёртка: текст слева, кнопка-карандаш справа.
        // Между строками небольшой вертикальный отступ.
        :deep(.email-row) {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 2px 10px;

            &:last-child{
                margin: 0;
            }

            &:hover{
                background: #e9e9e9;
            }

            .email-row__text {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                min-width: 0;

                .email-row__sent-icon {
                    margin-left: 6px;
                    vertical-align: middle;
                    color: $color-green-700;
                }
            }
            .email-row__edit-btn {
                flex: 0 0 auto;

                &:hover{
                    svg{
                        path{
                            stroke: $color-green-700;
                        }
                    }
                }
            }
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


.svg-eye{
    path{
        fill: $color-green-700;
    }
}

.email-modal-body {
    &__history {
        margin-bottom: 16px;
    }
    // Сохраняем переносы строк, но также переносим длинные строки/слова,
    // чтобы текст не вылезал по горизонтали из панели гармошки.
    &__letter {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        overflow-wrap: anywhere;
        font-family: inherit;
        font-size: 13px;
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
:deep(.company-edit-btn) {
    &:hover{
        svg{
            path{
                stroke: $color-green-700;
            }
        }
    }
}
:deep(.company-copy-btn) {
    &:hover{
        svg{
            path{
                stroke: $color-green-700;
            }
        }
    }
}
:deep(.company-delete-btn) {
    &:hover{
        svg{
            path{
                fill: $color-red-700;
            }
        }
    }
}

</style>
