/**
 * Копирование в буфер. navigator.clipboard есть только по HTTPS/localhost —
 * по http://<ip-в-локалке> работает запасной способ через скрытое поле.
 */
export async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
    document.body.appendChild(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();

    if (!copied) throw new Error('Не удалось скопировать');
}
