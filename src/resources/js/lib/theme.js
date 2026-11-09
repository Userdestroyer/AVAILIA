/**
 * Оформление: стиль × схема × шрифт акцентов. Значения — в паре с App\Support\Appearance;
 * цвета — в app.css (CSS-токены) и resources/js/backgrounds/<стиль>.js (полотно фона).
 */
export const STYLES = {
    jiva: { label: 'JIVA', hint: 'Y2K-стекло, светящиеся ленты' },
    classic: { label: 'Матовое стекло', hint: 'Исходный стиль, вены и огонь' },
};

export const THEMES = {
    light: { label: 'Светлая' },
    dark: { label: 'Тёмная' },
};

// Шрифты акцентов стиля JIVA: логотип, слоганы, подписи разрядкой
export const FONTS = {
    jura: { label: 'Jura', family: 'Jura' },
    exo2: { label: 'Exo 2', family: 'Exo 2' },
    unbounded: { label: 'Unbounded', family: 'Unbounded' },
};

export const DEFAULTS = { style: 'jiva', theme: 'light', font: 'jura' };
