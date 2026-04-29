import { createDiscreteApi } from 'naive-ui';

/**
 * Discrete API naive-ui — позволяет использовать message/notification
 * вне setup-контекста (из обычных JS-модулей, axios-обёрток и т.п.).
 *
 * Документация: https://www.naiveui.com/en-US/os-theme/docs/discrete
 */
const { message, notification } = createDiscreteApi(['message', 'notification']);

export { message, notification };
