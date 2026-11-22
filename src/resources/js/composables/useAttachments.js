import { computed, reactive, ref } from 'vue';
import { getJson, postForm } from '@/lib/http';

/**
 * Файлы к вопросу. Оптимистично: превью появляется сразу (из памяти браузера), сжатие и загрузка — фоном.
 * Галерея файлового поля — одна на всё приложение, грузится один раз (уже на наведение на скрепку).
 */
export const library = reactive({ files: [], loaded: false });
let loading = null;

export function loadLibrary() {
    loading ??= getJson('/files')
        .then(({ files }) => {
            // Загруженное, пока шёл запрос, уже стоит в начале
            const known = new Set(library.files.map((f) => f.id));
            library.files.push(...files.filter((f) => !known.has(f.id)));
            library.loaded = true;
        })
        .catch(() => {
            loading = null;
        });
    return loading;
}

const remember = (file) => {
    library.files = [file, ...library.files.filter((f) => f.id !== file.id)];
};

/**
 * @param {{ max_bytes: number, per_message: number }} limits
 * @param {(message: string) => void} onError
 */
export function useAttachments(limits, onError) {
    const items = ref([]);
    let seq = 0;

    const remove = (key) => {
        const item = items.value.find((i) => i.key === key);
        if (item?.preview) URL.revokeObjectURL(item.preview);
        items.value = items.value.filter((i) => i.key !== key);
    };

    const room = () => limits.per_message - items.value.length;
    const tooMany = () => onError(`К одному вопросу — не больше ${limits.per_message} файлов.`);

    const uploadOne = async (raw) => {
        const item = reactive({
            key: `f${++seq}`,
            file: null,
            name: raw.name,
            kind: raw.type === 'application/pdf' ? 'document' : 'image',
            preview: raw.type.startsWith('image/') ? URL.createObjectURL(raw) : null,
            status: 'uploading',
        });
        items.value.push(item);

        try {
            const { prepare } = await import('@/lib/files');
            const prepared = await prepare(raw, limits.max_bytes);
            const form = new FormData();
            form.append('file', prepared, prepared.name);
            const { file } = await postForm('/files', form);
            remember(file);
            // Тот же файл уже приложен — второй раз не нужен
            if (items.value.some((i) => i.key !== item.key && i.file?.id === file.id)) return remove(item.key);
            Object.assign(item, { file, name: file.name, status: 'ready' });
        } catch (e) {
            remove(item.key);
            onError(e.message);
        }
    };

    // Новые файлы: перетащили, вставили, выбрали — сразу в загрузку
    const add = (fileList) => {
        const incoming = [...fileList];
        if (incoming.length > room()) tooMany();
        return Promise.all(incoming.slice(0, Math.max(0, room())).map(uploadOne));
    };

    // Файл из галереи: приложить или убрать
    const toggle = (file) => {
        const existing = items.value.find((i) => i.file?.id === file.id);
        if (existing) return remove(existing.key);
        if (room() <= 0) return tooMany();
        items.value.push({ key: `f${++seq}`, file, name: file.name, kind: file.kind, preview: null, status: 'ready' });
    };

    const ready = computed(() => items.value.filter((i) => i.status === 'ready'));

    return {
        items,
        add,
        toggle,
        remove,
        ids: computed(() => ready.value.map((i) => i.file.id)),
        uploading: computed(() => items.value.some((i) => i.status === 'uploading')),
        // Для сообщения в ленте: своё превью из памяти, чтобы картинка не мигнула, пока грузится с сервера
        present: () => ready.value.map((i) => ({ ...i.file, thumb_url: i.preview ?? i.file.thumb_url })),
        // Отправка забирает файлы из поля ввода; не ушло — возвращает
        take: () => {
            const taken = items.value;
            items.value = [];
            return taken;
        },
        restore: (taken) => {
            items.value = [...taken, ...items.value];
        },
    };
}
