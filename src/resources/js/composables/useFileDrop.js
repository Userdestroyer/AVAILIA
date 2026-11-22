import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Файлы в окно: перетащили — сразу onFiles; dragging — пока файл над окном (подложка-подсказка).
 * onPaste — для поля ввода: скриншот из буфера (Ctrl+V) туда же.
 */
export function useFileDrop(onFiles) {
    const dragging = ref(false);
    let depth = 0;
    const carriesFiles = (event) => event.dataTransfer?.types?.includes('Files');

    const handlers = {
        dragenter: (event) => {
            if (!carriesFiles(event)) return;
            event.preventDefault();
            depth++;
            dragging.value = true;
        },
        dragover: (event) => carriesFiles(event) && event.preventDefault(),
        dragleave: (event) => {
            if (!carriesFiles(event)) return;
            depth = Math.max(0, depth - 1);
            dragging.value = depth > 0;
        },
        drop: (event) => {
            if (!carriesFiles(event)) return;
            event.preventDefault();
            depth = 0;
            dragging.value = false;
            onFiles(event.dataTransfer.files);
        },
    };
    onMounted(() => Object.entries(handlers).forEach(([name, handler]) => window.addEventListener(name, handler)));
    onBeforeUnmount(() => Object.entries(handlers).forEach(([name, handler]) => window.removeEventListener(name, handler)));

    const onPaste = (event) => {
        const files = [...(event.clipboardData?.files ?? [])];
        if (!files.length) return;
        event.preventDefault();
        onFiles(files);
    };

    return { dragging, onPaste };
}
