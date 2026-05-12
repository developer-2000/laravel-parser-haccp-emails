<template>
    <n-modal
        v-model:show="show"
        preset="card"
        :style="{ width: '500px' }"
        :segmented="{ content: true, footer: true }"
    >
        <template #header>
            Редактировать компанию #{{ companyId }}
        </template>

        <n-form-item label="Название" :show-feedback="false">
            <n-input
                v-model:value="name"
                placeholder="Название компании"
                @keydown.enter="save"
            />
        </n-form-item>

        <template #footer>
            <n-button
                type="info"
                strong
                secondary
                :loading="saving"
                :disabled="!name.trim() || saving"
                @click="save"
            >
                Сохранить
            </n-button>
        </template>
    </n-modal>
</template>

<script setup>
import { ref } from 'vue';
import apiAxios from '@/utils/apiAxios.js';
import { notification } from '@/utils/notify.js';

const emit = defineEmits(['saved']);

// Контекст модалки: id редактируемой компании и текущее значение name.
// Заполняются через метод open(); снаружи недоступны.
const show = ref(false);
const companyId = ref(null);
const name = ref('');
const saving = ref(false);

// Imperative API: открывает модалку для компании с подставленным name.
// Вызывается родителем через ref.
function open(companyIdValue, currentName) {
    companyId.value = companyIdValue;
    name.value = currentName ?? '';
    show.value = true;
}

// SERVER - PUT /companies/{id} с новым name. При успехе эмитит 'saved' —
// родитель локально обновит row.name в таблице компаний.
async function save() {
    const value = name.value.trim();
    if (!value || saving.value || !companyId.value) return;

    saving.value = true;

    const response = await apiAxios.put(`/companies/${companyId.value}`, { name: value });

    if (response?.status === 'success') {
        notification.success({
            content: response.message,
            duration: 5000,
            keepAliveOnHover: true,
        });
        emit('saved', {
            companyId: companyId.value,
            name: response?.data?.item?.name ?? value,
        });
        show.value = false;
    }

    saving.value = false;
}

defineExpose({ open });
</script>
