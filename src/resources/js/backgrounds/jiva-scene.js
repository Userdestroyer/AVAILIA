/**
 * 3D-сцена JIVA (docs/JIVA-STYLE.md, шаг 3): стеклянные закрученные колонны, стоящие на полу.
 * Камера и свет считаются на CPU один раз при запекании: вершины проецируются в экранное пространство p
 * (y от -0.5 до 0.5 по высоте, x — в тех же единицах от центра), освещение — по вершинам. Поэтому сцена
 * не зависит от пропорций окна («окно в сцену») и рисуется тем же 2D-пайплайном запекания.
 */

// Камера: глаз над полом, вертикальный угол обзора 40°, взгляд чуть вверх — горизонт ниже центра экрана
const FOV = (40 * Math.PI) / 180;
const EYE = [0, 1.2, 0];
const PITCH = Math.atan(0.4 * Math.tan(FOV / 2)); // горизонт на p.y = -0.2
const FOCAL = 0.5 / Math.tan(FOV / 2);

const sub = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
const add = (a, b) => [a[0] + b[0], a[1] + b[1], a[2] + b[2]];
const scale = (a, k) => [a[0] * k, a[1] * k, a[2] * k];
const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
const cross = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
const norm = (a) => scale(a, 1 / (Math.hypot(...a) || 1));

/**
 * Точка мира → экранное пространство p. Камера смотрит вдоль -z, наклонена вверх на PITCH.
 */
export function project(point) {
    const v = sub(point, EYE);
    const c = Math.cos(PITCH);
    const s = Math.sin(PITCH);
    // Поворот вокруг x: камера смотрит вверх на PITCH, горизонт уходит ниже центра экрана
    const y = v[1] * c + v[2] * s;
    const z = -v[1] * s + v[2] * c;
    const depth = -z;
    return [(v[0] / depth) * FOCAL, (y / depth) * FOCAL, depth];
}

// Горизонт (бесконечно далёкий пол) в экранных координатах
export const HORIZON = -0.5 * Math.tan(PITCH) / Math.tan(FOV / 2);

// Свет — сверху, слева и чуть сзади; блик по полу ведёт к горизонту
const LIGHT = norm([-0.5, 0.8, -0.35]);

/**
 * Колонны: центральная линия — кубическая Безье в мире (пол — y = 0), ширина ленты от основания к вершине,
 * закрутка сечения (оборотов), сторона (для отражения переливов), импульсы по рёбрам.
 */
// Стены — свои для широкого экрана и для портрета (телефон): «окно в сцену» узкое, ставим их у краёв
const SETS = { landscape: [], portrait: [] };

export const columnsFor = (aspect) => (aspect < 0.9 ? SETS.portrait : SETS.landscape);

/**
 * Стеклянная стена из двух слоёв: передний и задний со сдвигом и чуть другой формой — рёбра обоих
 * читаются как срез толстого обтекаемого стекла. dir — куда развёрнута ширина стены (перпендикулярно оси).
 */
function wall(set, base, layer) {
    const shift = layer ? base.layerShift : [0, 0, 0];
    const bend = layer ? 1.015 : 1;
    set.push({
        ...base,
        p: base.p.map((point, i) => (i === 1 || i === 2 ? add(scale(point, bend), shift) : add(point, shift))),
        hue: base.hue + (layer ? 0.18 : 0),
        gain: (layer ? 0.55 : 1) * (base.gain ?? 1),
        pulse: layer ? null : base.pulse,
    });
}

// Дальние стены: у самого горизонта (в ×5 дальше ближних — основание тонет в дымке горизонта), массивные
// и тусклые, без импульсов — глубина сцены. Рисуются первыми
const FAR_WALLS = [
    // Слева — широкая стена лицом к зрителю: занимает левую часть экрана, не стоит в затылок ближней
    { p: [[-62, 0, -150], [-52, 50, -150], [-74, 92, -146], [-68, 140, -136]], w: [86, 64], dir: [1, 0, 0.35], twist: 0, phase: 0,
        hue: 0.15, gain: 0.42, opacity: 0.6, layerShift: [3, 0, -2.5], pulse: null },
    // Справа — пологое широкое крыло у горизонта
    { p: [[36, 0, -144], [42, 34, -144], [62, 58, -138], [118, 64, -120]], w: [52, 26], dir: [0, 0.2, 1], twist: 0, phase: 0,
        hue: 0.55, gain: 0.5, opacity: 0.85, layerShift: [1.6, 1.2, -2], pulse: null },
];

// Телефон: кусочек S-стены у левого края вверху и крыла у правого края внизу — центр свободен
const PORTRAIT_WALLS = [
    // Низ — за левым краем, в кадр выходит только изгиб вверху
    { p: [[-3.2, 0, -11], [-3.0, 2, -11], [-1.2, 4.5, -11], [-3.0, 8, -10.5]], w: [2.6, 2.2], dir: [0.7, 0, 1], twist: 0, phase: 0,
        hue: 0.0, layerShift: [0.15, 0, -0.18], pulse: { speed: 0.06, dir: 1, period: 2.2, len: 0.28, phase: 0.1 } },
    // Низкое крыло ближе к зрителю — у правого края под горизонтом
    { p: [[1.2, 0, -8], [1.5, 1.2, -8], [2.4, 1.9, -7.6], [4.5, 2.1, -6.5]], w: [1.6, 0.9], dir: [0, 0.2, 1], twist: 0, phase: 0,
        hue: 0.35, layerShift: [0.12, 0.1, -0.18], pulse: { speed: 0.05, dir: 1, period: 2.4, len: 0.26, phase: 0.6 } },
];

const WALLS = [
    // Ось выходит из пола вертикально, а сечение у основания горизонтально вглубь сцены — нижняя кромка
    // ложится на пол ребром, по диагонали к зрителю, и согласуется с геометрией пола
    // Левая — высокая вертикальная S-стена
    { p: [[-4.4, 0, -11.5], [-2.9, 5, -11.5], [-5.9, 9.5, -11.5], [-4.6, 15, -10.5]], w: [3.0, 2.3], dir: [0.7, 0, 1], twist: 0.12, phase: 0, opacity: 1.05, gain: 0.82,
        hue: 0.0, layerShift: [0.18, 0, -0.2],
        pulse: { speed: 0.08, dir: 1, period: 2.8, len: 0.36, phase: 0.1 } },
    // Правая — выпуклое крыло: круто поднимается от пола правее центра и выполаживается к правому краю
    { p: [[2.0, 0, -10], [2.6, 3.4, -10], [5.6, 5.2, -9.5], [13, 5.6, -8]], w: [3.0, 1.6], dir: [0, 0.2, 1], twist: 0, phase: 0, opacity: 0.95, gain: 0.95,
        hue: 0.35, layerShift: [0.15, 0.12, -0.2],
        pulse: { speed: 0.07, dir: 1, period: 3.0, len: 0.34, phase: 0.6 } },
];
// Задний слой — первым, передний поверх; дальние стены — до ближних
for (const [set, walls] of [['landscape', [...FAR_WALLS, ...WALLS]], ['portrait', PORTRAIT_WALLS]]) {
    for (const base of walls) {
        wall(SETS[set], base, 1);
        wall(SETS[set], base, 0);
    }
}

const SEGMENTS = 160;

function bezier3(p, t) {
    const u = 1 - t;
    const k = [u * u * u, 3 * u * u * t, 3 * u * t * t, t * t * t];
    return [0, 1, 2].map((i) => k[0] * p[0][i] + k[1] * p[1][i] + k[2] * p[2][i] + k[3] * p[3][i]);
}

/**
 * Меш колонны в экранном пространстве: полоса поперёк ленты (across от -1 до 1), с освещением по вершинам.
 * Атрибуты (8 float): p.x, p.y, across, t, френель, блик, глубина (для отражения), сторона ребра.
 * Плюс два ребра — полилинии в p для импульсов.
 */
export function columnMesh(column, mirror = false) {
    const data = new Float32Array((SEGMENTS + 1) * 2 * 8);
    const edges = [[], []];

    for (let i = 0; i <= SEGMENTS; i++) {
        const t = i / SEGMENTS;
        const center = bezier3(column.p, t);
        const ahead = bezier3(column.p, Math.min(1, t + 0.002));
        const behind = bezier3(column.p, Math.max(0, t - 0.002));
        const tangent = norm(sub(ahead, behind));

        // Сечение: заданное направление стены, перпендикулярное оси; лёгкая закрутка вокруг оси
        const along = norm(sub(column.dir, scale(tangent, dot(column.dir, tangent))));
        const across = norm(cross(tangent, along));
        const angle = 2 * Math.PI * (column.twist * t + column.phase);
        const side = add(scale(along, Math.cos(angle)), scale(across, Math.sin(angle)));
        const normal = norm(cross(tangent, side));
        const width = column.w[0] + (column.w[1] - column.w[0]) * t;

        for (const s of [1, -1]) {
            let point = add(center, scale(side, width * s * 0.5));
            let n = normal;
            if (mirror) {
                point = [point[0], -point[1], point[2]];
                n = [n[0], -n[1], n[2]];
            }
            const [px, py, depth] = project(point);
            const view = norm(sub(EYE, point));
            const facing = Math.abs(dot(n, view));
            const fresnel = Math.pow(1 - facing, 3);
            const half = norm(add(LIGHT, view));
            const spec = Math.pow(Math.abs(dot(n, half)), 48);
            data.set([px, py, s, t, fresnel, spec, depth, s], (i * 2 + (s === 1 ? 0 : 1)) * 8);
            if (!mirror) edges[s === 1 ? 0 : 1].push([px, py]);
        }
    }

    return { data, count: (SEGMENTS + 1) * 2, edges };
}

/**
 * Ребро колонны → полоса для импульса (формат ribbonMesh: p.x, p.y, across, t, w, s; 6 float).
 * Ширина нулевая: импульс-шейдер рисует струю 1–2 px по самой линии.
 */
export function edgeStrip(points) {
    const count = points.length;
    const data = new Float32Array(count * 2 * 6);
    const reach = 0.006;
    let length = 0;
    for (let i = 0; i < count; i++) {
        const prev = points[Math.max(0, i - 1)];
        const next = points[Math.min(count - 1, i + 1)];
        const tx = next[0] - prev[0];
        const ty = next[1] - prev[1];
        const n = Math.hypot(tx, ty) || 1;
        if (i > 0) length += Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1]);
        for (const side of [1, -1]) {
            data.set([points[i][0] - (ty / n) * reach * side, points[i][1] + (tx / n) * reach * side, reach * side, i / (count - 1), 0, length], (i * 2 + (side === 1 ? 0 : 1)) * 6);
        }
    }
    return { data, count: count * 2 };
}
