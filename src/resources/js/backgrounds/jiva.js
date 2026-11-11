import { createProgram, FROST_GLSL, frostCollector, FULLSCREEN_VERTEX } from '@/backgrounds/gl';
import { columnMesh, columnsFor, edgeStrip, HORIZON } from '@/backgrounds/jiva-scene';
import { MAX_FROST_REGIONS } from '@/composables/useFrost';

/**
 * Стиль JIVA: Y2K-будущее, каким его видели в 2000-х (docs/JIVA-STYLE.md).
 *
 * «Запекание на устройстве»: статичная красота — небо, глянцевый пол, стеклянные ленты и их отражения —
 * рендерится один раз (открытие, смена схемы, смена ширины) в текстуру в разрешении экрана, полосами
 * по кадру. Каждый кадр — только дешёвое: вывод текстуры, матовое стекло под панелями из размытой копии
 * и импульсы света — мешами вдоль лент, шейдер считает лишь пиксели у линий.
 * Свой чанк — грузится, только когда выбран этот стиль.
 */

// Цвета — RGB 0–1 (CSS-цвета стекла — в app.css, в паре с этими)
const PALETTES = {
    dark: {
        skyTop: [0.008, 0.018, 0.07],
        skyHorizon: [0.06, 0.15, 0.46],
        floorFar: [0.08, 0.17, 0.48],
        floorNear: [0.012, 0.025, 0.09],
        horizonGlow: [0.32, 0.52, 1.0],
        // Сила свечения горизонта и блика на полу под ним
        horizonStrength: 0.45,
        floorShine: 0.26,
        rims: [[0.55, 0.86, 1.0], [0.42, 0.55, 1.0], [0.78, 0.55, 1.0]],
        body: [0.12, 0.28, 0.85],
        // Стеклянное тело колонн: чуть светлее ночного неба
        tint: [0.35, 0.58, 1.0],
        tintAlpha: 0.22,
        columnGain: 0.85,
        pulse: [0.72, 0.9, 1.0],
        exposure: 1.0,
        frostTint: [0.03, 0.07, 0.2],
        frostMix: 0.32,
        frostDim: 0.8,
        vignette: 0.32,
    },
    light: {
        skyTop: [0.06, 0.24, 0.76],
        skyHorizon: [0.5, 0.68, 0.97],
        floorFar: [0.66, 0.78, 1.0],
        floorNear: [0.36, 0.52, 0.9],
        horizonGlow: [0.95, 0.88, 1.0],
        // Светлая схема легко выгорает — горизонт и блик вдвое тише
        horizonStrength: 0.2,
        floorShine: 0.12,
        rims: [[0.92, 0.98, 1.0], [0.62, 0.86, 1.0], [1.0, 0.76, 0.94]],
        body: [0.75, 0.86, 1.0],
        // Стеклянное тело колонн: белёсо-голубое, светлее неба — как на рефах
        // Голубое, а не белое — наложения стен не выбеливаются
        tint: [0.6, 0.78, 1.0],
        tintAlpha: 0.3,
        columnGain: 0.85,
        pulse: [1.0, 1.0, 1.0],
        exposure: 0.85,
        // Дневное синее стекло: прозрачное, синеватое, сквозь него видны размытые ленты
        frostTint: [0.14, 0.33, 0.82],
        frostMix: 0.37,
        frostDim: 0.92,
        vignette: 0.1,
    },
};

/**
 * Композиции: ленты — кубические Безье. x — в долях полуширины экрана (-1…1), y — от -0.5 до 0.5 по высоте.
 * w — ширина тела от начала к концу (перспектива), glow — ореол, lit — освещённая кромка (±1),
 * reflect — отражается в полу, pulse — импульсы: скорость, направление, расстояние между ними, длина хвоста.
 */
// unit — сколько единиц высоты в одной «доле полуширины» композиции: x фигур задан в абсолютных
// единицах высоты, поэтому смена пропорций окна не сплющивает их, а лишь открывает больше или меньше сцены
const LANDSCAPE = {
    unit: 0.889,
    // Горизонт — из камеры 3D-сцены (ниже середины: пустой чат целиком на небе)
    horizon: HORIZON,
    ribbons: [
        // Боковые колонны — 3D (jiva-scene.js)
        // Дуги на полу — как отсветы колец
        { p: [[-1.1, -0.27], [-0.62, -0.4], [-0.3, -0.36], [-0.18, -0.27]], w: [0.004, 0.003], glow: 0.02, lit: 1, hue: 0.2, gain: 0.45, reflect: false },
        { p: [[1.1, -0.27], [0.62, -0.4], [0.3, -0.36], [0.18, -0.27]], w: [0.004, 0.003], glow: 0.02, lit: -1, hue: 0.8, gain: 0.45, reflect: false },
    ],
};

const PORTRAIT = {
    unit: 0.23,
    horizon: HORIZON,
    ribbons: [
    ],
};

// Горизонт — тоже лента: тонкая прямая во всю ширину
const horizonRibbon = (h) => ({ p: [[-1.3, h], [-0.4, h], [0.4, h], [1.3, h]], w: [0.0016, 0.0016], glow: 0.02, lit: 1, hue: 0.5, gain: 0.55, reflect: false,
    pulse: { speed: 0.05, dir: -1, period: 3.0, len: 0.5, phase: 0.8 } });

const SEGMENTS = 220;

// ── Шейдеры ────────────────────────────────────────────

const SKY_FRAGMENT = `
precision highp float;
uniform vec2 uRes;
uniform float uHorizon;
uniform vec3 uSkyTop;
uniform vec3 uSkyHorizon;
uniform vec3 uFloorFar;
uniform vec3 uFloorNear;
uniform vec3 uHorizonGlow;
uniform float uHorizonStrength;
uniform float uFloorShine;
uniform float uVignette;

void main() {
    vec2 uv = gl_FragCoord.xy / uRes;
    vec2 p = (gl_FragCoord.xy - 0.5 * uRes) / uRes.y;
    float h = uHorizon;
    vec3 col;

    if (p.y >= h) {
        // Небо: насыщенное сверху, светлее к горизонту
        col = mix(uSkyHorizon, uSkyTop, pow(smoothstep(h, 0.5, p.y), 0.65));
    } else {
        // Глянцевый пол: светлый у горизонта, глубже к зрителю; перспективные линии к точке схода
        float depth = smoothstep(h, -0.5, p.y);
        col = mix(uFloorFar, uFloorNear, pow(depth, 0.7));
        float angle = atan(p.x, h - p.y + 0.02);
        col += uHorizonGlow * pow(abs(cos(angle * 16.0)), 120.0) * pow(1.0 - depth, 3.0) * 0.02;
        // Отражённый свет горизонта — полоса под ним, затухает к зрителю
        col += uHorizonGlow * exp(-(h - p.y) * 9.0) * uFloorShine * (1.0 - 0.5 * abs(p.x));
    }

    // Свечение горизонта
    col += uHorizonGlow * exp(-abs(p.y - h) * 30.0) * uHorizonStrength * (1.0 - 0.45 * min(1.0, abs(p.x)));
    col *= 1.0 - uVignette * length(uv - 0.5);
    gl_FragColor = vec4(col, 1.0);
}
`;

// Меш ленты: полоса вдоль кривой; поперёк — расстояние от оси (aAcross), вдоль — t и длина дуги s
const RIBBON_VERTEX = `
attribute vec2 aPos;
attribute float aAcross;
attribute float aT;
attribute float aW;
attribute float aS;
uniform vec2 uRes;
uniform float uMirror;
uniform float uHorizon;
varying float vAcross;
varying float vT;
varying float vW;
varying float vS;
varying float vY;

void main() {
    vec2 p = aPos;
    // Отражение в полу — зеркально относительно горизонта
    if (uMirror > 0.5) p.y = 2.0 * uHorizon - p.y;
    vAcross = aAcross;
    vT = aT;
    vW = aW;
    vS = aS;
    vY = p.y;
    gl_Position = vec4(p.x * 2.0 * uRes.y / uRes.x, p.y * 2.0, 0.0, 1.0);
}
`;

// Стеклянная лента: тело, тонкий блик по освещённой кромке, переливчатая кромка, ореол
const RIBBON_FRAGMENT = `
precision highp float;
uniform vec2 uRes;
uniform vec3 uRimA;
uniform vec3 uRimB;
uniform vec3 uRimC;
uniform vec3 uBody;
uniform float uGlow;
uniform float uLit;
uniform float uHue;
uniform float uGain;
uniform float uMirror;
uniform float uHorizon;
varying float vAcross;
varying float vT;
varying float vW;
varying float vY;

void main() {
    float px = uRes.y;
    float soft = 1.0;
    float gain = uGain;
    if (uMirror > 0.5) {
        // Отражение — только ниже горизонта, мягче и тусклее с глубиной
        if (vY > uHorizon) discard;
        soft = 3.5;
        gain *= 0.4 * exp(-(uHorizon - vY) * 5.0);
    }

    float d = abs(vAcross);
    float edge = (vW - d) * px;
    float lit = sign(vAcross) * uLit > 0.0 ? 1.0 : 0.4;
    float ends = smoothstep(0.0, 0.12, vT) * (1.0 - smoothstep(0.88, 1.0, vT));

    // Переливчатая кромка: голубой → сиреневый → розовый вдоль ленты
    float h = fract(vT * 0.8 + uHue);
    vec3 rim = mix(mix(uRimA, uRimB, smoothstep(0.0, 0.5, h)), uRimC, smoothstep(0.45, 1.0, h));

    float line = exp(-pow(edge / (1.1 * soft), 2.0)) * lit;
    float inner = edge > 0.0 ? exp(-pow(edge / (0.25 * vW * px + 2.0), 2.0)) * lit * 0.5 : 0.0;
    // Стеклянное тело: плотнее к краям, как у трубки на просвет
    float body = edge > 0.0 ? 0.1 + 0.12 * pow(d / vW, 3.0) : 0.0;
    float spec = exp(-pow((vAcross - uLit * vW * 0.35) * px / (1.4 * soft), 2.0)) * 0.22;
    float outer = edge < 0.0 ? exp(-pow(-edge / (uGlow * px), 2.0)) * 0.22 : 0.0;

    vec3 col = uBody * body + rim * (line * 1.1 + inner + outer) + vec3(spec);
    gl_FragColor = vec4(col * ends * gain, 1.0);
}
`;

// 3D-колонна (вершины уже спроецированы и освещены на CPU): стеклянное тело, френель по кромкам,
// блик, тонкие переливчатые рёбра; отражение — тусклее и мягче
const COLUMN_VERTEX = `
attribute vec2 aPos;
attribute float aAcross;
attribute float aT;
attribute float aFresnel;
attribute float aSpec;
uniform vec2 uRes;
varying float vAcross;
varying float vT;
varying float vFresnel;
varying float vSpec;
varying float vY;

void main() {
    vAcross = aAcross;
    vT = aT;
    vFresnel = aFresnel;
    vSpec = aSpec;
    vY = aPos.y;
    gl_Position = vec4(aPos.x * 2.0 * uRes.y / uRes.x, aPos.y * 2.0, 0.0, 1.0);
}
`;

const COLUMN_FRAGMENT = `
#extension GL_OES_standard_derivatives : enable
precision highp float;
uniform vec3 uRimA;
uniform vec3 uRimB;
uniform vec3 uRimC;
uniform vec3 uBody;
uniform float uHue;
uniform float uGain;
uniform float uMirror;
uniform float uHorizon;
// 0 — тело (обычная прозрачность: глубина и объём), 1 — свет (кромки, френель, блик — «экраном»)
uniform float uPass;
uniform vec3 uTint;
uniform float uTintAlpha;
varying float vAcross;
varying float vT;
varying float vFresnel;
varying float vSpec;
varying float vY;

void main() {
    float gain = uGain;
    float soft = 1.0;
    if (uMirror > 0.5) {
        if (vY > uHorizon) discard;
        gain *= 0.35 * exp(-(uHorizon - vY) * 4.0);
        soft = 3.0;
    }
    // Ребро — тонкая линия в 1–2 px при любом масштабе
    float edge = 1.0 - abs(vAcross);
    float px = edge / max(fwidth(vAcross), 1e-4);
    // Кромка средней толщины: ядро 2–3 px и мягкий ореол
    float line = exp(-pow(px / (2.0 * soft), 2.0)) + 0.25 * exp(-pow(px / (6.0 * soft), 2.0));

    float h = fract(vT * 0.9 + uHue);
    vec3 rim = mix(mix(uRimA, uRimB, smoothstep(0.0, 0.5, h)), uRimC, smoothstep(0.45, 1.0, h));
    float ends = smoothstep(0.0, 0.05, vT) * (1.0 - smoothstep(0.9, 1.0, vT));

    if (uPass < 0.5) {
        // Тело: гуще в середине сечения и там, где смотрим прямо, — стекло читается объёмом
        // Стекло плотнее к краям сечения и под скользящим углом
        float alpha = uTintAlpha * (0.35 + 0.65 * max(1.0 - edge, vFresnel)) * ends * min(1.0, gain);
        gl_FragColor = vec4(uTint, alpha);
        return;
    }

    vec3 col = uBody * (0.12 + 0.2 * vFresnel)
        + rim * (vFresnel * 0.55 + line * 1.1)
        + vec3(1.0) * vSpec * 0.7 * (0.4 + 0.6 * edge);
    gl_FragColor = vec4(col * ends * gain, 1.0);
}
`;

// Размытие по Гауссу (линейные выборки), для матового стекла
const BLUR_FRAGMENT = `
precision highp float;
uniform sampler2D uTex;
uniform vec2 uDst;
uniform vec2 uStep;

void main() {
    vec2 uv = gl_FragCoord.xy / uDst;
    vec3 c = texture2D(uTex, uv).rgb * 0.2270270270;
    c += (texture2D(uTex, uv + uStep * 1.3846153846).rgb + texture2D(uTex, uv - uStep * 1.3846153846).rgb) * 0.3162162162;
    c += (texture2D(uTex, uv + uStep * 3.2307692308).rgb + texture2D(uTex, uv - uStep * 3.2307692308).rgb) * 0.0702702703;
    gl_FragColor = vec4(c, 1.0);
}
`;

// Кадр: запечённая сцена (с кроссфейдом при смене), матовое стекло под панелями, отсвет под ними
const COMPOSITE_FRAGMENT = `
precision highp float;
uniform vec2 uRes;
uniform sampler2D uScene;
uniform sampler2D uBlur;
uniform sampler2D uScenePrev;
uniform sampler2D uBlurPrev;
uniform float uMix;
// Размер, в котором запечены сцены: при ресайзе до перепекания картинка совпадает по высоте
// и обрезается/продлевается по бокам — не растягивается
uniform vec2 uSceneRes;
uniform vec2 uPrevRes;
uniform vec3 uFrostTint;
uniform float uFrostMix;
uniform float uFrostDim;
uniform vec3 uGlow;
${FROST_GLSL}

vec2 cover(vec2 res) {
    float k = res.y / uRes.y;
    return vec2(((gl_FragCoord.x - 0.5 * uRes.x) * k + 0.5 * res.x) / res.x, gl_FragCoord.y / uRes.y);
}

void main() {
    vec2 uv = cover(uSceneRes);
    vec2 uvPrev = cover(uPrevRes);
    vec3 col = mix(texture2D(uScenePrev, uvPrev).rgb, texture2D(uScene, uv).rgb, uMix);

    float frost = frostMask(gl_FragCoord.xy);
    if (frost > 0.0) {
        vec3 blurred = mix(texture2D(uBlurPrev, uvPrev).rgb, texture2D(uBlur, uv).rgb, uMix);
        col = mix(col, mix(blurred * uFrostDim, uFrostTint, uFrostMix), frost);
    }

    // Отсвет под панелями — как отражение на глянцевом полу
    for (int i = 0; i < ${MAX_FROST_REGIONS}; i++) {
        if (i >= uRectCount) break;
        vec4 r = uRects[i];
        float below = r.y - gl_FragCoord.y;
        if (below > 0.0) {
            float across = smoothstep(r.x, r.x + r.z * 0.3, gl_FragCoord.x) * smoothstep(r.x + r.z, r.x + r.z * 0.7, gl_FragCoord.x);
            col += uGlow * across * exp(-below / (uRes.y * 0.025)) * 0.1;
        }
    }

    // Дизеринг от полос на градиентах
    col += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 255.0;
    gl_FragColor = vec4(col, 1.0);
}
`;

// Импульс-«макаронина»: вытянутый свет вдоль ленты — яркая голова, затухающий хвост, лёгкий ореол
const PULSE_FRAGMENT = `
precision highp float;
uniform vec2 uRes;
uniform float uTime;
uniform float uSpeed;
uniform float uDir;
uniform float uPeriod;
uniform float uLen;
uniform float uPhase;
uniform float uThink;
uniform float uGain;
uniform vec3 uColor;
uniform float uLit;
varying float vAcross;
varying float vT;
varying float vW;
varying float vS;
${FROST_GLSL}

// Сколько света в точке s от «кометы»: q — расстояние за головой
float comet(float s, float shift) {
    float q = fract((uTime * uSpeed - uDir * s) / uPeriod + uPhase + shift) * uPeriod;
    return smoothstep(0.0, 0.015, q) * pow(1.0 - smoothstep(0.0, uLen, q), 1.6);
}

void main() {
    // Пока модель отвечает — вторая серия импульсов, между первыми
    float e = comet(vS, 0.0) + uThink * comet(vS, 0.5);
    // Тонкая струя (1–2 px) по освещённой кромке ленты и лёгкий ореол вокруг неё
    float px = abs(vAcross - uLit * vW) * uRes.y;
    float light = exp(-px * px) + exp(-px * px / 49.0) * 0.12;
    float ends = smoothstep(0.0, 0.1, vT) * (1.0 - smoothstep(0.9, 1.0, vT));
    // Под стеклом панелей — тише
    float frost = frostMask(gl_FragCoord.xy);
    gl_FragColor = vec4(uColor * e * light * ends * uGain * (1.0 - 0.6 * frost), 1.0);
}
`;

// ── Геометрия ──────────────────────────────────────────

function bezier(p, t) {
    const u = 1 - t;
    const a = u * u * u;
    const b = 3 * u * u * t;
    const c = 3 * u * t * t;
    const d = t * t * t;
    return [a * p[0][0] + b * p[1][0] + c * p[2][0] + d * p[3][0], a * p[0][1] + b * p[1][1] + c * p[2][1] + d * p[3][1]];
}

/**
 * Полоса вдоль ленты: на каждом шаге — две вершины по нормали на расстоянии тело + ореол.
 * Атрибуты: позиция (p), поперёк, t, ширина тела, длина дуги.
 */
function ribbonMesh(ribbon, unit) {
    const points = [];
    for (let i = 0; i <= SEGMENTS; i++) {
        const [x, y] = bezier(ribbon.p, i / SEGMENTS);
        points.push([x * unit, y]);
    }

    const data = new Float32Array((SEGMENTS + 1) * 2 * 6);
    let length = 0;
    for (let i = 0; i <= SEGMENTS; i++) {
        const prev = points[Math.max(0, i - 1)];
        const next = points[Math.min(SEGMENTS, i + 1)];
        const tx = next[0] - prev[0];
        const ty = next[1] - prev[1];
        const norm = Math.hypot(tx, ty) || 1;
        const nx = -ty / norm;
        const ny = tx / norm;
        if (i > 0) length += Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1]);

        const t = i / SEGMENTS;
        const w = ribbon.w[0] + (ribbon.w[1] - ribbon.w[0]) * t;
        const reach = w + ribbon.glow;
        for (const side of [1, -1]) {
            data.set([points[i][0] + nx * reach * side, points[i][1] + ny * reach * side, reach * side, t, w, length], (i * 2 + (side === 1 ? 0 : 1)) * 6);
        }
    }
    return data;
}

// ── Запуск ─────────────────────────────────────────────

export function create(canvas, { getThinking, getTheme, onReady }) {
    const gl = canvas.getContext('webgl', { antialias: false, alpha: false, powerPreference: 'low-power', preserveDrawingBuffer: false });
    if (!gl) return null;
    // Тонкие рёбра колонн — через производные (есть почти везде)
    gl.getExtension('OES_standard_derivatives');

    const programs = {
        sky: createProgram(gl, FULLSCREEN_VERTEX, SKY_FRAGMENT),
        ribbon: createProgram(gl, RIBBON_VERTEX, RIBBON_FRAGMENT),
        blur: createProgram(gl, FULLSCREEN_VERTEX, BLUR_FRAGMENT),
        composite: createProgram(gl, FULLSCREEN_VERTEX, COMPOSITE_FRAGMENT),
        pulse: createProgram(gl, RIBBON_VERTEX, PULSE_FRAGMENT),
        column: createProgram(gl, COLUMN_VERTEX, COLUMN_FRAGMENT),
    };

    const triangle = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, triangle);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);

    const frost = frostCollector(canvas);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Плотность пикселей — до 2: дальше разницы не видно, а память и время запекания растут
    const dpr = () => Math.min(window.devicePixelRatio || 1, 2);

    let layout = null; // { composition, ribbons: [{ ribbon, buffer, count }] }
    let current = null; // запечённая сцена { scene, blur, theme, width, height }
    let previous = null;
    let baking = null;
    let fade = 1;
    let raf = 0;
    let last = 0;
    let lastDrawn = 0;
    let time = Math.random() * 10;
    let think = getThinking() ? 1 : 0;
    let ready = false;
    let resizeTimer = 0;

    const fullscreen = (program) => {
        gl.bindBuffer(gl.ARRAY_BUFFER, triangle);
        const pos = program.attrib('aPos');
        gl.enableVertexAttribArray(pos);
        gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
        gl.disableVertexAttribArray(pos);
    };

    const target = (width, height) => {
        const texture = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, texture);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, width, height, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        const framebuffer = gl.createFramebuffer();
        gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
        gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
        return { texture, framebuffer, width, height };
    };

    const release = (scene) => {
        if (!scene) return;
        for (const t of [scene.scene, scene.blurA, scene.blurB]) {
            gl.deleteTexture(t.texture);
            gl.deleteFramebuffer(t.framebuffer);
        }
    };

    // Ленты под текущую форму экрана: портрет — своя композиция
    const buildLayout = () => {
        for (const item of layout?.ribbons ?? []) gl.deleteBuffer(item.buffer);
        for (const c of layout?.columns ?? []) [c.mesh, c.mirror, ...c.edges].forEach((m) => gl.deleteBuffer(m.buffer));
        const aspect = canvas.width / canvas.height;
        const composition = aspect < 0.9 ? PORTRAIT : LANDSCAPE;
        const ribbons = [...composition.ribbons, horizonRibbon(composition.horizon)].map((ribbon) => {
            const buffer = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
            gl.bufferData(gl.ARRAY_BUFFER, ribbonMesh(ribbon, composition.unit), gl.STATIC_DRAW);
            return { ribbon, buffer, count: (SEGMENTS + 1) * 2 };
        });
        const buffer = (data) => {
            const b = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, b);
            gl.bufferData(gl.ARRAY_BUFFER, data, gl.STATIC_DRAW);
            return b;
        };
        const columns = columnsFor(aspect).map((column) => {
            const mesh = columnMesh(column);
            const mirror = columnMesh(column, true);
            return {
                column,
                mesh: { buffer: buffer(mesh.data), count: mesh.count },
                mirror: { buffer: buffer(mirror.data), count: mirror.count },
                // Рёбра — полосы для импульсов (струя по самой кромке)
                edges: column.pulse === null ? [] : mesh.edges.map((points) => {
                    const strip = edgeStrip(points);
                    return { buffer: buffer(strip.data), count: strip.count, ribbon: { lit: 1, gain: 1, pulse: column.pulse } };
                }),
            };
        });
        layout = { composition, ribbons, aspect, columns };
    };

    const drawMesh = (program, item) => {
        gl.bindBuffer(gl.ARRAY_BUFFER, item.buffer);
        const attributes = [['aPos', 2, 0], ['aAcross', 1, 2], ['aT', 1, 3], ['aW', 1, 4], ['aS', 1, 5]];
        const enabled = [];
        for (const [name, size, offset] of attributes) {
            const loc = program.attrib(name);
            if (loc < 0) continue;
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, size, gl.FLOAT, false, 24, offset * 4);
            enabled.push(loc);
        }
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, item.count);
        enabled.forEach((loc) => gl.disableVertexAttribArray(loc));
    };

    // Меш колонны: 8 float на вершину (p.x, p.y, поперёк, t, френель, блик, глубина, сторона)
    const drawColumn = (program, mesh) => {
        gl.bindBuffer(gl.ARRAY_BUFFER, mesh.buffer);
        const enabled = [];
        for (const [name, size, offset] of [['aPos', 2, 0], ['aAcross', 1, 2], ['aT', 1, 3], ['aFresnel', 1, 4], ['aSpec', 1, 5]]) {
            const loc = program.attrib(name);
            if (loc < 0) continue;
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, size, gl.FLOAT, false, 32, offset * 4);
            enabled.push(loc);
        }
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, mesh.count);
        enabled.forEach((loc) => gl.disableVertexAttribArray(loc));
    };

    // ── Запекание: полосами, по одной за кадр — без рывка ──
    const STRIPS = 6;

    const startBake = () => {
        if (baking) release(baking);
        const width = canvas.width;
        const height = canvas.height;
        const small = [Math.max(1, Math.round(width / 4)), Math.max(1, Math.round(height / 4))];
        baking = {
            scene: target(width, height),
            blurA: target(...small),
            blurB: target(...small),
            theme: getTheme(),
            width,
            height,
            strip: 0,
        };
    };

    const bakeStep = () => {
        const job = baking;
        const palette = PALETTES[job.theme] ?? PALETTES.light;
        const { composition } = layout;

        gl.bindFramebuffer(gl.FRAMEBUFFER, job.scene.framebuffer);
        gl.viewport(0, 0, job.width, job.height);
        gl.enable(gl.SCISSOR_TEST);
        const stripHeight = Math.ceil(job.height / STRIPS);
        gl.scissor(0, job.strip * stripHeight, job.width, stripHeight);

        // Небо и пол
        gl.disable(gl.BLEND);
        const sky = programs.sky;
        gl.useProgram(sky.program);
        gl.uniform2f(sky.u('uRes'), job.width, job.height);
        gl.uniform1f(sky.u('uHorizon'), composition.horizon);
        gl.uniform3fv(sky.u('uSkyTop'), palette.skyTop);
        gl.uniform3fv(sky.u('uSkyHorizon'), palette.skyHorizon);
        gl.uniform3fv(sky.u('uFloorFar'), palette.floorFar);
        gl.uniform3fv(sky.u('uFloorNear'), palette.floorNear);
        gl.uniform3fv(sky.u('uHorizonGlow'), palette.horizonGlow);
        gl.uniform1f(sky.u('uHorizonStrength'), palette.horizonStrength);
        gl.uniform1f(sky.u('uFloorShine'), palette.floorShine);
        gl.uniform1f(sky.u('uVignette'), palette.vignette);
        fullscreen(sky);

        // Ленты и их отражения — «экраном»: свет мягко насыщается, а не выгорает в белое
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_COLOR);
        const ribbon = programs.ribbon;
        gl.useProgram(ribbon.program);
        gl.uniform2f(ribbon.u('uRes'), job.width, job.height);
        gl.uniform1f(ribbon.u('uHorizon'), composition.horizon);
        gl.uniform3fv(ribbon.u('uRimA'), palette.rims[0]);
        gl.uniform3fv(ribbon.u('uRimB'), palette.rims[1]);
        gl.uniform3fv(ribbon.u('uRimC'), palette.rims[2]);
        gl.uniform3fv(ribbon.u('uBody'), palette.body);
        for (const mirror of [1, 0]) {
            gl.uniform1f(ribbon.u('uMirror'), mirror);
            for (const item of layout.ribbons) {
                if (mirror && !item.ribbon.reflect) continue;
                gl.uniform1f(ribbon.u('uGlow'), item.ribbon.glow);
                gl.uniform1f(ribbon.u('uLit'), item.ribbon.lit);
                gl.uniform1f(ribbon.u('uHue'), item.ribbon.hue);
                gl.uniform1f(ribbon.u('uGain'), item.ribbon.gain * palette.exposure);
                drawMesh(ribbon, item);
            }
        }
        // 3D-колонны: сначала отражения в полу, потом сами
        const column = programs.column;
        gl.useProgram(column.program);
        gl.uniform2f(column.u('uRes'), job.width, job.height);
        gl.uniform1f(column.u('uHorizon'), composition.horizon);
        gl.uniform3fv(column.u('uRimA'), palette.rims[0]);
        gl.uniform3fv(column.u('uRimB'), palette.rims[1]);
        gl.uniform3fv(column.u('uRimC'), palette.rims[2]);
        gl.uniform3fv(column.u('uBody'), palette.body);
        gl.uniform1f(column.u('uGain'), palette.exposure);
        gl.uniform3fv(column.u('uTint'), palette.tint);
        gl.uniform1f(column.u('uTintAlpha'), palette.tintAlpha);
        for (const mirror of [1, 0]) {
            gl.uniform1f(column.u('uMirror'), mirror);
            for (const pass of [0, 1]) {
                gl.uniform1f(column.u('uPass'), pass);
                // Тело — обычной прозрачностью, свет — «экраном»
                if (pass === 0) gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
                else gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_COLOR);
                for (const item of layout.columns) {
                    gl.uniform1f(column.u('uHue'), item.column.hue);
                    gl.uniform1f(column.u('uGain'), palette.exposure * palette.columnGain * (item.column.gain ?? 1));
                    gl.uniform1f(column.u('uTintAlpha'), palette.tintAlpha * palette.columnGain * (item.column.opacity ?? 1));
                    drawColumn(column, mirror ? item.mirror : item.mesh);
                }
            }
        }
        gl.disable(gl.BLEND);
        gl.disable(gl.SCISSOR_TEST);

        if (++job.strip < STRIPS) return;

        // Размытая копия для матового стекла: уменьшение и два прохода гаусса туда-обратно
        const blur = programs.blur;
        gl.useProgram(blur.program);
        const pass = (from, to, step) => {
            gl.bindFramebuffer(gl.FRAMEBUFFER, to.framebuffer);
            gl.viewport(0, 0, to.width, to.height);
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, from.texture);
            gl.uniform1i(blur.u('uTex'), 0);
            gl.uniform2f(blur.u('uDst'), to.width, to.height);
            gl.uniform2f(blur.u('uStep'), step[0] / from.width, step[1] / from.height);
            fullscreen(blur);
        };
        pass(job.scene, job.blurA, [2, 0]);
        pass(job.blurA, job.blurB, [0, 2]);
        pass(job.blurB, job.blurA, [3, 0]);
        pass(job.blurA, job.blurB, [0, 3]);
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);

        // Готово: прежняя сцена плавно уступает новой
        release(previous);
        previous = current;
        current = { scene: job.scene, blur: job.blurB, blurA: job.blurA, blurB: job.blurB, theme: job.theme, width: job.width, height: job.height };
        baking = null;
        fade = previous ? 0 : 1;
    };

    const composite = (dt) => {
        if (fade < 1) fade = Math.min(1, fade + dt / 0.45);
        if (fade >= 1 && previous) {
            release(previous);
            previous = null;
        }

        gl.bindFramebuffer(gl.FRAMEBUFFER, null);
        gl.viewport(0, 0, canvas.width, canvas.height);
        const palette = PALETTES[current.theme] ?? PALETTES.light;
        const prev = previous ?? current;

        const program = programs.composite;
        gl.useProgram(program.program);
        gl.uniform2f(program.u('uRes'), canvas.width, canvas.height);
        [current.scene, current.blur, prev.scene, prev.blur].forEach((t, i) => {
            gl.activeTexture(gl.TEXTURE0 + i);
            gl.bindTexture(gl.TEXTURE_2D, t.texture);
        });
        gl.uniform1i(program.u('uScene'), 0);
        gl.uniform1i(program.u('uBlur'), 1);
        gl.uniform1i(program.u('uScenePrev'), 2);
        gl.uniform1i(program.u('uBlurPrev'), 3);
        gl.uniform1f(program.u('uMix'), fade);
        gl.uniform2f(program.u('uSceneRes'), current.width, current.height);
        gl.uniform2f(program.u('uPrevRes'), prev.width, prev.height);
        gl.uniform3fv(program.u('uFrostTint'), palette.frostTint);
        gl.uniform1f(program.u('uFrostMix'), palette.frostMix);
        gl.uniform1f(program.u('uFrostDim'), palette.frostDim);
        gl.uniform3fv(program.u('uGlow'), palette.horizonGlow);
        frost.apply(gl, program.u);
        fullscreen(program);

        if (reducedMotion) return;

        // Импульсы — поверх, «экраном»
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_COLOR);
        const pulse = programs.pulse;
        gl.useProgram(pulse.program);
        gl.uniform2f(pulse.u('uRes'), canvas.width, canvas.height);
        gl.uniform1f(pulse.u('uMirror'), 0);
        gl.uniform1f(pulse.u('uTime'), time);
        gl.uniform1f(pulse.u('uThink'), think);
        gl.uniform3fv(pulse.u('uColor'), palette.pulse);
        frost.apply(gl, pulse.u);
        for (const item of [...layout.ribbons, ...layout.columns.flatMap((c) => c.edges)]) {
            const p = item.ribbon.pulse;
            if (!p) continue;
            gl.uniform1f(pulse.u('uSpeed'), p.speed);
            gl.uniform1f(pulse.u('uDir'), p.dir);
            gl.uniform1f(pulse.u('uPeriod'), p.period);
            gl.uniform1f(pulse.u('uLen'), p.len);
            gl.uniform1f(pulse.u('uPhase'), p.phase);
            gl.uniform1f(pulse.u('uLit'), item.ribbon.lit);
            gl.uniform1f(pulse.u('uGain'), (0.9 + 0.5 * think) * item.ribbon.gain);
            drawMesh(pulse, item);
        }
        gl.disable(gl.BLEND);
    };

    const loop = (now) => {
        raf = requestAnimationFrame(loop);
        const dt = Math.min(0.1, (now - (last || now)) / 1000);
        last = now;
        think += ((getThinking() ? 1 : 0) - think) * Math.min(1, dt * 1.5);
        // Путь импульсов копится: ускорение при мышлении не переставляет их скачком
        time += dt * (reducedMotion ? 0 : 1 + 1.4 * think);

        // Схема сменилась — запекаем новую сцену, старая плавно уступит
        if (current && !baking && current.theme !== getTheme()) startBake();
        if (baking) {
            bakeStep();
            if (!current) return;
        }

        const moved = frost.collect();
        // В покое импульсы медленные — хватает 30 кадров; когда модель отвечает, панели едут или идёт смена — 60
        const busy = think > 0.05 || moved || fade < 1;
        if (!busy && now - lastDrawn < 1000 / 30 - 2) return;
        lastDrawn = now;
        composite(dt);

        if (!ready) {
            ready = true;
            onReady?.();
        }
        // Без движения кадр после готовности не нужен — цикл спит до события
        if (reducedMotion && !baking && fade >= 1) cancelAnimationFrame(raf);
    };

    const start = () => {
        cancelAnimationFrame(raf);
        last = 0;
        raf = requestAnimationFrame(loop);
    };

    const size = () => {
        canvas.width = Math.max(1, Math.round(canvas.clientWidth * dpr()));
        canvas.height = Math.max(1, Math.round(canvas.clientHeight * dpr()));
    };

    // Ресайз: разрешение канваса — сразу (подложки панелей не уезжают), старая сцена показывается
    // «окном» без растяжения; перепекаем, когда окно перестало меняться
    const onResize = () => {
        const width = Math.round(canvas.clientWidth * dpr());
        const height = Math.round(canvas.clientHeight * dpr());
        if (width === canvas.width && height === canvas.height) return;
        size();
        start();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            buildLayout();
            startBake();
            start();
        }, 250);
    };

    const onVisibility = () => {
        cancelAnimationFrame(raf);
        if (!document.hidden) start();
    };

    size();
    buildLayout();
    startBake();
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', onVisibility);
    start();

    return {
        // Без анимации (reduced motion) кадр рисуется по событию: смена схемы, мышление
        redraw: () => reducedMotion && start(),
        destroy() {
            cancelAnimationFrame(raf);
            clearTimeout(resizeTimer);
            window.removeEventListener('resize', onResize);
            document.removeEventListener('visibilitychange', onVisibility);
            release(baking);
            release(previous);
            release(current);
            for (const item of layout?.ribbons ?? []) gl.deleteBuffer(item.buffer);
            for (const c of layout?.columns ?? []) [c.mesh, c.mirror, ...c.edges].forEach((m) => gl.deleteBuffer(m.buffer));
            gl.deleteBuffer(triangle);
            Object.values(programs).forEach((program) => program.destroy());
            // Отдаём контекст браузеру сразу, не дожидаясь сборщика мусора
            gl.getExtension('WEBGL_lose_context')?.loseContext();
        },
    };
}
