import axios from "axios";

/**
 * Разбор Laravel / типичного JSON с полем errors → одна строка (разделитель — перенос строки;
 * для HTML-тостов можно заменить на <br>).
 *
 */
export function getApiErrors(error) {
    const errorData =
        error && typeof error === "object" && "response" in error
            ? error.response?.data ?? {}
            : {};
    let errorMessages = "";

    if (typeof errorData.errors !== "undefined") {
        if (errorData.errors) {
            if (Array.isArray(errorData.errors) && errorData.errors.length) {
                if (
                    errorData.errors.every(
                        (item) => typeof item === "object" && item !== null,
                    )
                ) {
                    errorMessages = errorData.errors
                        .map((obj) => Object.values(obj).join("\n"))
                        .join("\n");
                } else if (
                    errorData.errors.every((item) => typeof item === "string")
                ) {
                    errorMessages = errorData.errors.join("\n");
                } else {
                    errorMessages =
                        errorData.message || "An unknown error occurred";
                }
            } else if (
                typeof errorData.errors === "object" &&
                errorData.errors !== null
            ) {
                const hasArrays = Object.values(errorData.errors).some(
                    (value) => Array.isArray(value) && value.length > 0,
                );
                if (hasArrays) {
                    errorMessages = Object.values(errorData.errors)
                        .flat()
                        .map((item) =>
                            Array.isArray(item) ? item.join("\n") : String(item),
                        )
                        .join("\n");
                } else {
                    errorMessages =
                        Object.values(errorData.errors).flat().join("\n") ||
                        errorData.message ||
                        "Validation failed";
                }
            } else {
                errorMessages =
                    errorData.message || "An unknown error occurred";
            }
        } else if (
            Array.isArray(errorData.errors) &&
            !errorData.errors.length
        ) {
            errorMessages = errorData.message || "Validation failed";
        }
    } else {
        errorMessages =
            (typeof errorData.message === "string" && errorData.message) ||
            "An unknown error occurred";
    }

    return errorMessages;
}

/** Проверяет, что ошибка — это отмена запроса, а не реальный фейл API. */
function isCanceledError(error) {
    if (axios.isCancel?.(error)) {
        return true;
    }
    return (
        error?.code === "ERR_CANCELED" ||
        error?.name === "CanceledError" ||
        error?.name === "AbortError"
    );
}

export class ApiAxios {
    /**
     * Создает axios-клиент с базовым URL и общими заголовками.
     */
    constructor(options = {}) {

        this.options = { ...options };

        this.client = axios.create({
            baseURL: this.options.baseURL ?? "",
            withCredentials: this.options.withCredentials ?? false,
            validateStatus: (status) => status === 200,
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                Accept: "application/json",
                ...this.options.headers,
            },
        });

    }

    /** Привязывает abort к `beforeunload`, чтобы не оставлять висящие запросы при уходе со страницы. */
    _attachUnloadAbort(userSignal, useUnload = true) {
        const controller = new AbortController();
        const onUnload = () => {
            try {
                controller.abort(new DOMException("Page unloading", "AbortError"));
            } catch {
                controller.abort();
            }
        };
        if (useUnload) {
            window.addEventListener("beforeunload", onUnload);
        }
        let signal = controller.signal;
        if (userSignal && typeof AbortSignal !== "undefined" && AbortSignal.any) {
            signal = AbortSignal.any([userSignal, controller.signal]);
        } else if (userSignal) {
            signal = userSignal;
        }
        return {
            signal,
            detach: () => {
                if (useUnload) {
                    window.removeEventListener("beforeunload", onUnload);
                }
            },
        };
    }

    /** Готовит конфиг запроса: выносит служебные флаги и добавляет `signal` для отмены. */
    _prepareConfig(config = {}) {
        const { unloadAbort, signal: userSignal, ...axiosConfig } = config;
        const { signal, detach } = this._attachUnloadAbort(
            userSignal,
            unloadAbort !== false,
        );
        return { axiosConfig: { ...axiosConfig, signal }, detach };
    }

    /** Единая обработка ошибок: либо возвращает строку, либо показывает toast. */
    _handleError(error, returnError) {
        if (isCanceledError(error)) {
            return null;
        }

        const showToast = this.options.showToast;

        if (error?.message === "Network Error") {
            showToast?.(
                "Error",
                "No internet connection or the server is unavailable.",
            );
            return returnError ? "Network Error" : null;
        }
        if (error?.code === "ECONNABORTED") {
            showToast?.(
                "Error",
                "The request took too long, the server might be unavailable.",
            );
            return returnError ? "Timeout" : null;
        }

        const status = error?.response?.status ?? "Error";
        let errorMessages = getApiErrors(error);
        if (typeof errorMessages !== "string") {
            errorMessages = "An unknown error occurred.";
        }
        if (returnError) {
            return errorMessages;
        }
        showToast?.(String(status), errorMessages);
        return null;
    }

    /**
     * Выполняет GET-запрос и возвращает полный axios response.
     */
    async get(url, params = {}, returnError = false, config = {}) {
        const { axiosConfig, detach } = this._prepareConfig(config);
        try {
            const response = await this.client.get(url, {
                ...axiosConfig,
                params,
            });
            return response.data;
        } catch (error) {
            return this._handleError(error, returnError);
        } finally {
            detach();
        }
    }

    /**
     * Выполняет POST-запрос и возвращает `response.data`.
     */
    async post(url, data, returnError = false, config = {}) {
        const { axiosConfig, detach } = this._prepareConfig(config);
        try {
            const response = await this.client.post(url, data, axiosConfig);
            return response.data;
        } catch (error) {
            return this._handleError(error, returnError);
        } finally {
            detach();
        }
    }

    /**
     * Выполняет PUT-запрос и возвращает `response.data`.
     */
    async put(url, data, returnError = false, config = {}) {
        const { axiosConfig, detach } = this._prepareConfig(config);
        try {
            const response = await this.client.put(url, data, axiosConfig);
            return response.data;
        } catch (error) {
            return this._handleError(error, returnError);
        } finally {
            detach();
        }
    }

    /**
     * Выполняет DELETE-запрос и возвращает `response.data`.
     */
    async destroy(url, returnError = false, config = {}) {
        const { axiosConfig, detach } = this._prepareConfig(config);
        try {
            const response = await this.client.delete(url, axiosConfig);
            return response.data;
        } catch (error) {
            return this._handleError(error, returnError);
        } finally {
            detach();
        }
    }

}

/**
 * Инстанс по умолчанию (тот же origin, без тостов и редиректов). Для публичных GET вроде `/survey/questions`.
 */
const defaultApiAxios = new ApiAxios({
    baseURL: "",
});

export default defaultApiAxios;
