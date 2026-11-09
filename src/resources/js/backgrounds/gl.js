import { frostRegions, MAX_FROST_REGIONS } from '@/composables/useFrost';

/**
 * Общая обвязка фон-шейдеров: контекст, полноэкранный треугольник, ресайз, цикл кадров,
 * пауза в фоновой вкладке, матовые области под панелями (v-frost) и полное освобождение
 * видеопамяти при уничтожении — два стиля никогда не держат GPU одновременно.
 */
export const FROST_GLSL = `
uniform vec4 uRects[${MAX_FROST_REGIONS}];
uniform float uRadii[${MAX_FROST_REGIONS}];
uniform int uRectCount;

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
`;

const VERTEX = `
attribute vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }
`;

/**
 * @param {HTMLCanvasElement} canvas
 * @param {{ fragment: string, uniforms: string[], scale: number, frame: (ctx: object) => void, fps?: (ctx: object) => number }} options
 *   frame — выставить uniform'ы кадра; fps — желаемая частота кадров (экономия, когда фон почти неподвижен)
 * @returns {{ redraw: () => void, destroy: () => void } | null} null — WebGL недоступен
 */
export function runShader(canvas, { fragment, uniforms, scale, frame, fps }) {
    const gl = canvas.getContext('webgl', { antialias: false, alpha: false, powerPreference: 'low-power' });
    if (!gl) return null;

    const compile = (type, source) => {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(shader));
        return shader;
    };

    const vertex = compile(gl.VERTEX_SHADER, VERTEX);
    const fragmentShader = compile(gl.FRAGMENT_SHADER, fragment);
    const program = gl.createProgram();
    gl.attachShader(program, vertex);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);
    gl.useProgram(program);

    // Адреса uniform'ов — один раз, а не на каждом кадре
    const u = Object.fromEntries(['uRes', 'uRects', 'uRadii', 'uRectCount', ...uniforms].map((name) => [name, gl.getUniformLocation(program, name)]));

    // Один треугольник на весь экран
    const buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    const pos = gl.getAttribLocation(program, 'aPos');
    gl.enableVertexAttribArray(pos);
    gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);

    const rects = new Float32Array(MAX_FROST_REGIONS * 4);
    const radii = new Float32Array(MAX_FROST_REGIONS);

    // Прямоугольники матовых панелей в координатах канваса (ось Y снизу вверх)
    const collectFrost = () => {
        const k = canvas.width / window.innerWidth;
        let count = 0;
        for (const [el, radius] of frostRegions) {
            if (count === MAX_FROST_REGIONS) break;
            const box = el.getBoundingClientRect();
            if (!box.width || !box.height) continue; // скрытая панель
            rects.set([box.left * k, canvas.height - box.bottom * k, box.width * k, box.height * k], count * 4);
            radii[count] = radius * k;
            count++;
        }
        return count;
    };

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let raf = 0;
    let last = 0;
    let lastDrawn = 0;

    const draw = (dt) => {
        gl.uniform2f(u.uRes, canvas.width, canvas.height);
        gl.uniform1i(u.uRectCount, collectFrost());
        gl.uniform4fv(u.uRects, rects);
        gl.uniform1fv(u.uRadii, radii);
        frame({ gl, u, dt, width: canvas.width, height: canvas.height });
        gl.drawArrays(gl.TRIANGLES, 0, 3);
    };

    const loop = (now) => {
        raf = requestAnimationFrame(loop);
        const dt = Math.min(0.1, (now - (last || now)) / 1000);
        last = now;
        // Ограничение частоты: копим время, рисуем, когда пора — фаза от пропуска кадров не прыгает
        const target = fps?.({ dt }) ?? 60;
        if (target < 60 && now - lastDrawn < 1000 / target - 2) {
            frame({ gl, u, dt, width: canvas.width, height: canvas.height, skip: true });
            return;
        }
        lastDrawn = now;
        draw(dt);
    };

    const resize = () => {
        canvas.width = Math.max(1, Math.round(window.innerWidth * scale));
        canvas.height = Math.max(1, Math.round(window.innerHeight * scale));
        gl.viewport(0, 0, canvas.width, canvas.height);
        if (reducedMotion) draw(0);
    };

    const onVisibility = () => {
        cancelAnimationFrame(raf);
        if (!document.hidden && !reducedMotion) {
            last = 0;
            raf = requestAnimationFrame(loop);
        }
    };

    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', onVisibility);
    if (reducedMotion) draw(0);
    else raf = requestAnimationFrame(loop);

    return {
        // При reduced motion анимации нет — кадр перерисовывается по событию (смена темы, мышление)
        redraw: () => reducedMotion && draw(0),
        destroy() {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', resize);
            document.removeEventListener('visibilitychange', onVisibility);
            gl.deleteBuffer(buffer);
            gl.deleteProgram(program);
            gl.deleteShader(vertex);
            gl.deleteShader(fragmentShader);
            // Отдаём контекст браузеру сразу, не дожидаясь сборщика мусора
            gl.getExtension('WEBGL_lose_context')?.loseContext();
        },
    };
}

/**
 * Плавно подводит палитру к целевой (смена светлой/тёмной схемы без рывка).
 */
export function easeTowards(current, target, k) {
    for (const key of Object.keys(target)) {
        if (typeof target[key] === 'string') continue;
        if (Array.isArray(target[key])) {
            for (let i = 0; i < target[key].length; i++) current[key][i] += (target[key][i] - current[key][i]) * k;
        } else {
            current[key] += (target[key] - current[key]) * k;
        }
    }
}
