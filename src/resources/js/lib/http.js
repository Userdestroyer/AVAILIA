// JSON-запрос к Laravel: CSRF-токен берётся из куки XSRF-TOKEN, которую ставит сам Laravel
function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function postJson(url, body) {
    return requestJson('POST', url, body);
}

export function putJson(url, body) {
    return requestJson('PUT', url, body);
}

export function patchJson(url, body) {
    return requestJson('PATCH', url, body);
}

export function deleteJson(url) {
    return requestJson('DELETE', url);
}

async function requestJson(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    // 204 No Content — тела нет
    const data = response.status === 204 ? {} : await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'Не удалось выполнить запрос');
    }

    return data;
}
