import { easeTowards, FROST_GLSL, runShader } from '@/backgrounds/gl';

/**
 * Стиль «Матовое стекло» (classic): вены каустики в покое, огонь, пока модель думает и пишет.
 * Свой чанк — грузится только когда выбран этот стиль.
 */

// Полотно и вены в покое по схеме; цвета — RGB 0–1 (CSS-цвета стекла — в app.css, в паре с этими)
const PALETTES = {
    light: {
        canvasLow: [0.17, 0.2, 0.27],
        canvasHigh: [0.33, 0.37, 0.45],
        idleMain: [0.36, 0.56, 1.0],
        idleSide: [1.0, 0.55, 0.32],
        idleSideMix: 0.55,
        // Яркость вен в покое: тише, чтобы не спорить с текстом
        idleGlow: 0.75,
        // Насколько матовое стекло приглушает свет под собой (в паре с --glass-dim)
        glassDim: 0.69,
    },
    dark: {
        // Графит и свеча: тусклый тёплый огонь с фиолетовым краем
        canvasLow: [0.055, 0.06, 0.07],
        canvasHigh: [0.15, 0.16, 0.175],
        idleMain: [0.95, 0.56, 0.26],
        idleSide: [0.58, 0.4, 0.88],
        idleSideMix: 0.6,
        idleGlow: 0.55,
        glassDim: 0.63,
    },
};

// Модель думает или стримит: в обеих схемах — огонь, ярче покоя
const FIRE = { main: [1.0, 0.42, 0.16], side: [1.0, 0.68, 0.36], glow: 1.2 };

const FRAGMENT = `
precision highp float;
uniform vec2 uRes;
// Фаза течения — уже с учётом скорости (копится в JS), а не «время × скорость»
uniform float uPhase;
uniform float uThink;
// Полотно и вены в покое — из палитры схемы (PALETTES)
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

${FROST_GLSL}
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
    // Виньетка — только у фона: стекло лежит поверх и везде одинаковое, иначе панели
    // у краёв экрана (боковые) выглядели бы темнее центральных
    col *= 1.0 - 0.28 * length(uv - 0.5);

    float frost = frostMask(gl_FragCoord.xy);
    if (frost > 0.0) {
        vec3 frosted = base + accent * (pow(ridge, 1.8) * 0.6) * glow;
        col = mix(col, frosted * uGlassDim, frost);
    }

    // Дизеринг от полос на градиентах
    col += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 255.0;

    gl_FragColor = vec4(col, 1.0);
}
`;

const SPEED_IDLE = 0.025;
const SPEED_THINKING = 0.035;

export function create(canvas, { getThinking, getTheme }) {
    const palette = structuredClone(PALETTES[getTheme()] ?? PALETTES.light);
    let think = getThinking() ? 1 : 0;
    // Фазу копим шагами dt × скорость: если умножать время на меняющуюся скорость, фон «мельтешит»
    let phase = Math.random() * 25;

    return runShader(canvas, {
        fragment: FRAGMENT,
        // Фон размытый — рисуем в пониженном разрешении и растягиваем
        scale: 0.35,
        uniforms: ['uPhase', 'uThink', 'uCanvasLow', 'uCanvasHigh', 'uIdleMain', 'uIdleSide', 'uIdleSideMix', 'uIdleGlow', 'uFireMain', 'uFireSide', 'uFireGlow', 'uGlassDim'],
        frame({ gl, u, dt }) {
            // Цвет мышления и палитра схемы догоняют цель плавно, примерно за пару секунд
            think += ((getThinking() ? 1 : 0) - think) * (dt ? Math.min(1, dt * 1.2) : 1);
            phase += dt * (SPEED_IDLE + (SPEED_THINKING - SPEED_IDLE) * think);
            easeTowards(palette, PALETTES[getTheme()] ?? PALETTES.light, dt ? Math.min(1, dt * 2.5) : 1);

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
        },
    });
}
