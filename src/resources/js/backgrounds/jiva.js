import { easeTowards, FROST_GLSL, runShader } from '@/backgrounds/gl';
import { MAX_FROST_REGIONS } from '@/composables/useFrost';

/**
 * Стиль JIVA: Y2K-будущее, каким его видели в 2000-х. Неподвижные светящиеся ленты и горизонт,
 * по лентам бегут импульсы света; пока модель думает и пишет — чаще и ярче.
 * Свой чанк — грузится только когда выбран этот стиль.
 */

// Цвета — RGB 0–1 (CSS-цвета стекла — в app.css, в паре с этими)
const PALETTES = {
    dark: {
        skyTop: [0.008, 0.025, 0.11],
        skyBottom: [0.02, 0.07, 0.24],
        horizon: [0.25, 0.5, 1.0],
        core: [0.82, 0.92, 1.0],
        halo: [0.18, 0.42, 1.0],
        accent: [0.5, 0.36, 1.0],
        glow: 1.0,
        // Стекло: во что подкрашивает фон под собой и насколько (меньше — прозрачнее)
        frostTint: [0.03, 0.07, 0.2],
        frostMix: 0.36,
        vignette: 0.35,
    },
    light: {
        skyTop: [0.17, 0.38, 0.85],
        skyBottom: [0.6, 0.77, 0.98],
        horizon: [0.8, 0.88, 1.0],
        core: [1.0, 1.0, 1.0],
        halo: [0.72, 0.84, 1.0],
        accent: [0.86, 0.88, 1.0],
        glow: 0.85,
        frostTint: [0.88, 0.93, 1.0],
        frostMix: 0.34,
        vignette: 0.12,
    },
};

const FRAGMENT = `
precision highp float;
uniform vec2 uRes;
// Фаза импульсов (копится в JS), их частота и сила — растут, пока модель отвечает
uniform float uPhase;
uniform float uPulseFreq;
uniform float uPulseGain;
uniform vec3 uSkyTop;
uniform vec3 uSkyBottom;
uniform vec3 uHorizon;
uniform vec3 uCore;
uniform vec3 uHalo;
uniform vec3 uAccent;
uniform float uGlow;
uniform vec3 uFrostTint;
uniform float uFrostMix;
uniform float uVignette;
${FROST_GLSL}

// Лента: кривая y = a + b·x + c·x² + e·sin(f·x + g), пучок тонких линий с ореолом.
// blur > 1 — под стеклом: линии шире и тусклее (размытие без лишних выборок)
vec3 ribbon(vec2 p, vec4 q, vec2 wave, float spread, vec3 tint, float k, float ph, float blur) {
    float x = p.x;
    float y = q.x + q.y * x + q.z * x * x + q.w * sin(wave.x * x + wave.y);
    float slope = q.y + 2.0 * q.z * x + q.w * wave.x * cos(wave.x * x + wave.y);
    float d = (p.y - y) / sqrt(1.0 + slope * slope);

    float w = 0.0017 * blur;
    float d1 = d - spread;
    float d2 = d + spread * 0.7;
    float core = (exp(-d * d / (w * w)) + 0.55 * exp(-d1 * d1 / (w * w)) + 0.35 * exp(-d2 * d2 / (w * w))) / blur;
    float hw = 0.03 * (0.6 + 0.4 * blur);
    float halo = exp(-d * d / (hw * hw));
    float fill = exp(-pow((d - spread * 0.15) / (spread * 1.6), 2.0));

    // Импульс бежит вдоль ленты слева направо
    float pulse = pow(0.5 + 0.5 * cos(6.2831853 * (x * k * uPulseFreq - uPhase + ph)), 28.0);

    return mix(tint, uCore, 0.7) * core * (1.0 + pulse * uPulseGain)
        + tint * (halo * (0.32 + pulse * 0.9 * uPulseGain * 0.5) + fill * 0.1);
}

void main() {
    vec2 uv = gl_FragCoord.xy / uRes;
    vec2 p = (gl_FragCoord.xy - 0.5 * uRes) / uRes.y;
    float frost = frostMask(gl_FragCoord.xy);
    float blur = mix(1.0, 7.0, frost);

    // Небо и свет горизонта с «полом» под ним
    vec3 col = mix(uSkyBottom, uSkyTop, smoothstep(-0.5, 0.5, p.y));
    float dh = p.y + 0.33;
    col += uHorizon * (exp(-abs(dh) * 16.0) * 0.45 + (dh < 0.0 ? exp(dh * 5.0) * 0.18 : 0.0));

    // Плавные дуги, как в интерфейсах из 2000-х: восходящая, арка сверху, низкая волна, дальняя дуга, горизонт
    vec3 light = vec3(0.0);
    light += ribbon(p, vec4(0.12, 0.22, 0.20, 0.0), vec2(1.0, 0.0), 0.018, uHalo, 0.55, 0.0, blur);
    light += ribbon(p, vec4(0.42, -0.10, -0.30, 0.0), vec2(1.0, 0.0), 0.022, uAccent, 0.45, 0.37, blur);
    light += ribbon(p, vec4(-0.24, 0.10, 0.10, 0.025), vec2(3.5, 1.0), 0.014, uHalo, 0.7, 0.71, blur);
    light += 0.5 * ribbon(p, vec4(-0.05, -0.30, 0.18, 0.0), vec2(1.0, 0.0), 0.03, uAccent, 0.4, 0.15, blur);
    light += 0.8 * ribbon(p, vec4(-0.33, 0.0, 0.0, 0.004), vec2(9.0, 0.0), 0.006, uHorizon, 0.8, 0.5, blur);
    // Под стеклом свет вдвое тише — не спорит с текстом
    col += light * uGlow * mix(1.0, 0.5, frost);

    // Отсвет под панелями — как отражение на глянцевом полу
    for (int i = 0; i < ${MAX_FROST_REGIONS}; i++) {
        if (i >= uRectCount) break;
        vec4 r = uRects[i];
        float below = r.y - gl_FragCoord.y;
        if (below > 0.0) {
            float across = smoothstep(r.x, r.x + r.z * 0.25, gl_FragCoord.x) * smoothstep(r.x + r.z, r.x + r.z * 0.75, gl_FragCoord.x);
            col += uCore * across * exp(-below / (uRes.y * 0.03)) * 0.12;
        }
    }

    // Виньетка — только у фона: стекло поверх везде одинаковое
    col *= 1.0 - uVignette * length(uv - 0.5);
    col = mix(col, mix(col, uFrostTint, uFrostMix), frost);

    // Дизеринг от полос на градиентах
    col += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 255.0;
    gl_FragColor = vec4(col, 1.0);
}
`;

const SPEED_IDLE = 0.055;
const SPEED_THINKING = 0.16;

export function create(canvas, { getThinking, getTheme }) {
    const palette = structuredClone(PALETTES[getTheme()] ?? PALETTES.light);
    let think = getThinking() ? 1 : 0;
    let phase = Math.random();

    return runShader(canvas, {
        fragment: FRAGMENT,
        // Линии тонкие — разрешение выше, чем у размытых вен classic
        scale: 0.5,
        uniforms: ['uPhase', 'uPulseFreq', 'uPulseGain', 'uSkyTop', 'uSkyBottom', 'uHorizon', 'uCore', 'uHalo', 'uAccent', 'uGlow', 'uFrostTint', 'uFrostMix', 'uVignette'],
        // Импульсы медленные — в покое хватает 30 кадров; пока модель отвечает, 60
        fps: () => (think > 0.05 ? 60 : 30),
        frame({ gl, u, dt, skip }) {
            think += ((getThinking() ? 1 : 0) - think) * (dt ? Math.min(1, dt * 1.5) : 1);
            phase += dt * (SPEED_IDLE + (SPEED_THINKING - SPEED_IDLE) * think);
            easeTowards(palette, PALETTES[getTheme()] ?? PALETTES.light, dt ? Math.min(1, dt * 2.5) : 1);
            if (skip) return;

            gl.uniform1f(u.uPhase, phase);
            gl.uniform1f(u.uPulseFreq, 1 + 0.9 * think);
            gl.uniform1f(u.uPulseGain, 1.6 + 1.8 * think);
            for (const key of ['skyTop', 'skyBottom', 'horizon', 'core', 'halo', 'accent', 'frostTint']) {
                gl.uniform3fv(u[`u${key[0].toUpperCase()}${key.slice(1)}`], palette[key]);
            }
            gl.uniform1f(u.uGlow, palette.glow * (1 + 0.25 * think));
            gl.uniform1f(u.uFrostMix, palette.frostMix);
            gl.uniform1f(u.uVignette, palette.vignette);
        },
    });
}
