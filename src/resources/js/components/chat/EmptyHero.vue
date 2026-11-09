<script setup>
/**
 * Пустой чат: знак — светящаяся дуга с точкой, имя разрядкой и слоган. Цвета и шрифт — из токенов
 * оформления (в JIVA — шрифт акцентов и широкая разрядка, в classic — Inter).
 */
</script>

<template>
    <div class="hero-in flex flex-col items-center">
        <!-- Знак: дуга-горизонт, над ней точка — «восход» -->
        <svg viewBox="0 0 220 64" class="h-12 w-40 overflow-visible md:h-14 md:w-48" aria-hidden="true">
            <defs>
                <linearGradient id="hero-arc" x1="0" x2="1">
                    <stop offset="0" stop-color="var(--color-cool)" stop-opacity="0" />
                    <stop offset="0.5" stop-color="var(--color-cool-soft)" />
                    <stop offset="1" stop-color="var(--color-cool)" stop-opacity="0" />
                </linearGradient>
            </defs>
            <path d="M8 58 C 70 58, 82 22, 110 22 S 150 58, 212 58" fill="none" stroke="url(#hero-arc)" stroke-width="2" class="hero-glow" />
            <circle cx="110" cy="8" r="3.5" fill="var(--color-cool-soft)" class="hero-glow" />
        </svg>

        <h1 class="brand mt-3 text-3xl font-light text-fg/95 md:text-5xl">Availia</h1>
        <p class="brand mt-3 text-[10px] text-(--accent-text) md:text-xs">Все нейросети — в одной точке</p>
        <div class="mt-5 h-px w-40 bg-gradient-to-r from-transparent via-cool to-transparent shadow-[0_0_12px_var(--color-cool)]" />
    </div>
</template>
