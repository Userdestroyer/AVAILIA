<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { frostRegions, MAX_FROST_REGIONS } from '@/composables/useFrost';
import { useTheme } from '@/composables/useTheme';
import { FIRE, THEMES } from '@/lib/theme';

const props = defineProps({
    // Модель думает — вены перетекают из синего в огненный
    thinking: { type: Boolean, default: false },
});

const canvas = ref(null);
const { theme } = useTheme();

// Фон размытый, поэтому рисуем в пониженном разрешении и растягиваем — дёшево для GPU
const RESOLUTION_SCALE = 0.35;

const VERTEX = `
attribute vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }
`;

const FRAGMENT = `
precision highp float;
uniform vec2 uRes;
// Фаза течения — уже с учётом скорости (копится в JS), а не «время × скорость»
uniform float uPhase;
uniform float uThink;
// Полотно и вены в покое — из палитры темы (lib/theme.js)
uniform vec3 uCanvasLow;
uniform vec3 uCanvasHigh;
uniform vec3 uIdleMain;
uniform vec3 uIdleSide;
uniform float uIdleSideMix;
uniform float uIdleGlow;
// Огонь мышления — общий для тем
uniform vec3 uFireMain;
uniform vec3 uFireSide;
uniform float uFireGlow;
// Матовые панели: прямоугольники (x, y, w, h в пикселях канваса) и радиусы скругления
uniform vec4 uRects[${MAX_FROST_REGIONS}];
uniform float uRadii[${MAX_FROST_REGIONS}];
uniform int uRectCount;
// Насколько стекло приглушает свет под собой
uniform float uGlassDim;

vec2 hash(vec2 p) {
    p = vec2(dot(p, vec2(127.1, 311.7)), dot(p, vec2(269.5, 183.3)));
    return -1.0 + 2.0 * fract(sin(p) * 43758.5453123);
}

// Градиентный шум
float noise(vec2 p) {
    vec2 i = floor(p), f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(dot(hash(i), f), dot(hash(i + vec2(1.0, 0.0)), f - vec2(1.0, 0.0)), u.x),
        mix(dot(hash(i + vec2(0.0, 1.0)), f - vec2(0.0, 1.0)), dot(hash(i + vec2(1.0, 1.0)), f - vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm(vec2 p) {
    float v = 0.0, a = 0.5;
    mat2 m = mat2(1.6, 1.2, -1.2, 1.6);
    for (int i = 0; i < 4; i++) { v += a * noise(p); p = m * p; a *= 0.5; }
    return v;
}

float sdRoundBox(vec2 p, vec2 halfSize, float radius) {
    vec2 q = abs(p) - halfSize + radius;
    return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - radius;
}

// 1 — пиксель под матовой панелью, 0 — снаружи (край сглажен)
float frostMask(vec2 pixel) {
    float mask = 0.0;
    for (int i = 0; i < ${MAX_FROST_REGIONS}; i++) {
        if (i >= uRectCount) break;
        vec4 rect = uRects[i];
        float d = sdRoundBox(pixel - rect.xy - rect.zw * 0.5, rect.zw * 0.5, uRadii[i]);
        mask = max(mask, 1.0 - smoothstep(-0.75, 0.75, d));
    }
    return mask;
}

void main() {
    vec2 uv = gl_FragCoord.xy / uRes;
    vec2 p = (gl_FragCoord.xy - 0.5 * uRes) / uRes.y;
    float t = uPhase;

    // Вытягиваем пространство по диагонали — волны идут полосами, как на каустике
    p = mat2(0.8, -0.6, 0.6, 0.8) * p * vec2(0.32, 0.9);

    // Двойная деформация домена — медленное «течение» трафарета
    vec2 q = vec2(fbm(p + vec2(0.0, t)), fbm(p + vec2(5.2, -t)));
    vec2 r = vec2(fbm(p + 1.6 * q + vec2(1.7, 9.2) + 0.7 * t), fbm(p + 1.6 * q + vec2(8.3, 2.8) - 0.5 * t));
    float f = fbm(p * 0.9 + 1.8 * r);

    // Гребни шума — это и есть «вены»: маска, через которую проступает цвет
    float ridge = clamp(1.0 - abs(f) * 2.2, 0.0, 1.0);
    float veins = pow(ridge, 7.0);
    float halo = pow(ridge, 2.5);

    // Серая подложка
    vec3 base = mix(uCanvasLow, uCanvasHigh, uv.y * 0.8 + 0.25 * q.x);

    // Покой — цвета темы с отсветом с одного края; мышление — огонь и ярче
    float side = smoothstep(-0.3, 0.9, uv.x + 0.4 * r.y - 0.2);
    vec3 idle = mix(uIdleMain, uIdleSide, side * uIdleSideMix);
    vec3 fire = mix(uFireMain, uFireSide, side);
    vec3 accent = mix(idle, fire, uThink);
    float glow = mix(uIdleGlow, uFireGlow, uThink);

    vec3 col = base + accent * (veins * 0.55 + halo * 0.2) * glow;
    col += vec3(1.0) * veins * 0.04 * glow;

    // Под матовыми панелями — «размытая» версия: тонкая вена расплывается в широкое мягкое
    // свечение (меньшая степень гребня ≈ сильное размытие линии), плюс приглушение стеклом.
    // Считается из того же гребня — пиксель не дороже, чем без размытия.
    float frost = frostMask(gl_FragCoord.xy);
    if (frost > 0.0) {
        vec3 frosted = base + accent * (pow(ridge, 1.8) * 0.6) * glow;
        col = mix(col, frosted * uGlassDim, frost);
    }

    // Виньетка и дизеринг от полос на градиентах
    col *= 1.0 - 0.28 * length(uv - 0.5);
    col += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 255.0;

    gl_FragColor = vec4(col, 1.0);
}
`;

let gl;
let program;
let frame = 0;
let think = props.thinking ? 1 : 0;
let last = 0;
// Фазу копим шагами dt × скорость: если умножать накопленное время на меняющуюся скорость,
// при смене цвета фаза прыгает на сотни единиц и фон «мельтешит»
let phase = Math.random() * 25;
const SPEED_IDLE = 0.025;
const SPEED_THINKING = 0.035;

// Текущая палитра — плавно догоняет палитру выбранной темы
const PALETTE_KEYS = ['canvasLow', 'canvasHigh', 'idleMain', 'idleSide', 'idleSideMix', 'idleGlow', 'glassDim'];
const palette = structuredClone(Object.fromEntries(PALETTE_KEYS.map((key) => [key, THEMES[theme.value][key]])));

function easePalette(k) {
    const target = THEMES[theme.value];
    for (const key of PALETTE_KEYS) {
        if (Array.isArray(palette[key])) {
            for (let i = 0; i < 3; i++) palette[key][i] += (target[key][i] - palette[key][i]) * k;
        } else {
            palette[key] += (target[key] - palette[key]) * k;
        }
    }
}
let reducedMotion = false;

function compile(type, source) {
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        throw new Error(gl.getShaderInfoLog(shader));
    }
    return shader;
}

function resize() {
    const el = canvas.value;
    el.width = Math.max(1, Math.round(window.innerWidth * RESOLUTION_SCALE));
    el.height = Math.max(1, Math.round(window.innerHeight * RESOLUTION_SCALE));
    gl.viewport(0, 0, el.width, el.height);
    if (reducedMotion) draw();
}

// Адреса uniform'ов — один раз, а не на каждом кадре
const UNIFORMS = [
    'uRes', 'uPhase', 'uThink', 'uCanvasLow', 'uCanvasHigh', 'uIdleMain', 'uIdleSide', 'uIdleSideMix',
    'uIdleGlow', 'uFireMain', 'uFireSide', 'uFireGlow', 'uRects', 'uRadii', 'uRectCount', 'uGlassDim',
];
let u = {};
const rects = new Float32Array(MAX_FROST_REGIONS * 4);
const radii = new Float32Array(MAX_FROST_REGIONS);

// Прямоугольники матовых панелей в координатах канваса (ось Y снизу вверх)
function collectFrostRegions() {
    const scale = canvas.value.width / window.innerWidth;
    const height = canvas.value.height;
    let count = 0;

    for (const [el, radius] of frostRegions) {
        if (count === MAX_FROST_REGIONS) break;
        const box = el.getBoundingClientRect();
        if (!box.width || !box.height) continue; // скрытая панель

        rects.set([box.left * scale, height - box.bottom * scale, box.width * scale, box.height * scale], count * 4);
        radii[count] = radius * scale;
        count++;
    }

    return count;
}

function draw() {
    gl.uniform2f(u.uRes, canvas.value.width, canvas.value.height);
    gl.uniform1f(u.uPhase, phase);
    gl.uniform1f(u.uThink, think);
    gl.uniform3fv(u.uCanvasLow, palette.canvasLow);
    gl.uniform3fv(u.uCanvasHigh, palette.canvasHigh);
    gl.uniform3fv(u.uIdleMain, palette.idleMain);
    gl.uniform3fv(u.uIdleSide, palette.idleSide);
    gl.uniform1f(u.uIdleSideMix, palette.idleSideMix);
    gl.uniform1f(u.uIdleGlow, palette.idleGlow);
    gl.uniform3fv(u.uFireMain, FIRE.main);
    gl.uniform3fv(u.uFireSide, FIRE.side);
    gl.uniform1f(u.uFireGlow, FIRE.glow);
    gl.uniform1f(u.uGlassDim, palette.glassDim);
    gl.uniform1i(u.uRectCount, collectFrostRegions());
    gl.uniform4fv(u.uRects, rects);
    gl.uniform1fv(u.uRadii, radii);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
}

function loop(now) {
    const dt = Math.min(0.1, (now - (last || now)) / 1000);
    last = now;
    // Плавный переход цвета примерно за пару секунд
    think += ((props.thinking ? 1 : 0) - think) * Math.min(1, dt * 1.2);
    phase += dt * (SPEED_IDLE + (SPEED_THINKING - SPEED_IDLE) * think);
    easePalette(Math.min(1, dt * 2.5));
    draw();
    frame = requestAnimationFrame(loop);
}

function onVisibility() {
    cancelAnimationFrame(frame);
    if (!document.hidden && !reducedMotion) {
        last = 0;
        frame = requestAnimationFrame(loop);
    }
}

onMounted(() => {
    gl = canvas.value.getContext('webgl', { antialias: false, alpha: false, powerPreference: 'low-power' });
    if (!gl) return; // без WebGL остаётся CSS-градиент контейнера

    program = gl.createProgram();
    gl.attachShader(program, compile(gl.VERTEX_SHADER, VERTEX));
    gl.attachShader(program, compile(gl.FRAGMENT_SHADER, FRAGMENT));
    gl.linkProgram(program);
    gl.useProgram(program);
    u = Object.fromEntries(UNIFORMS.map((name) => [name, gl.getUniformLocation(program, name)]));

    // Шейдер готов рисовать матовые панели — CSS снимает с них дорогой backdrop-filter
    document.documentElement.dataset.frost = 'gl';

    // Один треугольник на весь экран
    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    const pos = gl.getAttribLocation(program, 'aPos');
    gl.enableVertexAttribArray(pos);
    gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);

    reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', onVisibility);

    if (reducedMotion) draw();
    else frame = requestAnimationFrame(loop);
});

// При reduced motion анимации нет — перерисовываем кадр со сменой цвета сразу
watch(() => props.thinking, (value) => {
    if (reducedMotion && gl) {
        think = value ? 1 : 0;
        draw();
    }
});

watch(theme, () => {
    if (reducedMotion && gl) {
        easePalette(1);
        draw();
    }
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    delete document.documentElement.dataset.frost;
    window.removeEventListener('resize', resize);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <div class="pointer-events-none fixed inset-0 -z-10 bg-(--app-bg)">
        <canvas ref="canvas" class="size-full" aria-hidden="true" />
    </div>
</template>
