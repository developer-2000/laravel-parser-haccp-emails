<template>
    <n-modal
        v-model:show="show"
        preset="card"
        content-scrollable
        :style="{ width: '600px', height: '90vh' }"
        :segmented="{ content: true, footer: true }"
    >
        <template #header>
            {{ email }}
        </template>

        <div class="email-letter-modal__body">
            <!-- История писем для этой пары (company_id + email).
                 По умолчанию все панели свернуты (default-expanded-names не задан).
                 Заголовок панели — title письма (если есть) либо превью текста,
                 внутри — заголовок (если был) + полный текст. -->
            <n-collapse
                v-if="history.length"
                class="email-letter-modal__history"
                accordion
            >
                <n-collapse-item
                    v-for="item in history"
                    :key="item.id"
                    :name="String(item.id)"
                    :title="item.title || letterPreview(item.letter)"
                >
                    <div v-if="item.title" class="email-letter-modal__title">{{ item.title }}</div>
                    <div class="email-letter-modal__letter">{{ item.letter }}</div>
                </n-collapse-item>
            </n-collapse>

            <n-input
                v-model:value="title"
                placeholder="Тема письма"
                class="email-letter-modal__title-input"
            />

            <n-input
                v-model:value="letter"
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
                :loading="sending"
                :disabled="!letter.trim() || !title.trim() || sending"
                @click="send"
            >
                Отправить
            </n-button>
        </template>
    </n-modal>
</template>

<script setup>
import { ref } from 'vue';
import apiAxios from '@/utils/apiAxios.js';
import { notification } from '@/utils/notify.js';

const emit = defineEmits(['sent']);

// Контекст модалки: какой email какой компании редактируется.
// Заполняются через метод open(); снаружи недоступны.
const show = ref(false);
const companyId = ref(null);
const email = ref('');
const title = ref('');
const letter = ref('');
const sending = ref(false);

// История писем для текущей пары (company_id + email) — массив
// { id, letter, created_at }, в обратном порядке (новые сверху).
const history = ref([]);

// Сокращённый превью текста письма для заголовка гармошки.
// Берём первую непустую строку, обрезаем до 60 символов с многоточием.
function letterPreview(value) {
    const first = String(value ?? '').split('\n').find((l) => l.trim()) ?? '';
    return first.length > 60 ? first.slice(0, 60).trimEnd() + '…' : first;
}

// SERVER - открывает модалку для пары (companyId, email)
// и подгружает историю писем. Вызывается родителем через ref.
async function open(companyIdValue, emailValue) {
    companyId.value = companyIdValue;
    email.value = emailValue;
    title.value = '';
    letter.value = '';
    history.value = [];
    show.value = true;

    // Подгружаем историю писем для гармошки. Ошибки молча проглатываем —
    // apiAxios сам покажет toast, а пустая история не блокирует ввод нового.
    const response = await apiAxios.get('/company-emails', {
        company_id: companyIdValue,
        email: emailValue,
    });
    if (response?.status === 'success') {
        history.value = response?.data?.items ?? [];
    }
}

// SERVER - сохраняет письмо для пары (company_id + email) в таблицу.
// При успехе эмитит 'sent' — родитель обновит sent_emails в своей таблице.
async function send() {
    const text = letter.value.trim();
    const subject = title.value.trim();
    if (!text || !subject || sending.value || !companyId.value) return;

    sending.value = true;

    const response = await apiAxios.post('/company-emails', {
        company_id: companyId.value,
        email: email.value,
        title: subject,
        letter: text,
    });

    if (response?.status === 'success') {
        notification.success({
            content: response.message,
            duration: 5000,
            keepAliveOnHover: true,
        });
        emit('sent', {
            companyId: companyId.value,
            email: email.value,
            title: subject,
            letter: text,
        });
        // Добавляем только что отправленное письмо в начало истории.
        // Бэкенд не возвращает item — собираем синтетический локально,
        // чтобы гармошка обновилась без повторного запроса. id отрицательный
        // (Date.now() со знаком минус), чтобы не конфликтовал с реальными.
        history.value = [
            {
                id: -Date.now(),
                title: subject,
                letter: text,
                created_at: new Date().toISOString(),
            },
            ...history.value,
        ];
        show.value = false;
    }

    sending.value = false;
}

defineExpose({ open });
</script>

<style scoped lang="scss">
.email-letter-modal {
    &__history {
        margin-bottom: 16px;
    }
    &__title-input {
        margin-bottom: 12px;
    }
    // Заголовок (subject) внутри развёрнутой панели истории — над текстом письма.
    &__title {
        margin: 0 0 8px 0;
        font-weight: 600;
        font-size: 14px;
        color: #273a55;
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
</style>
