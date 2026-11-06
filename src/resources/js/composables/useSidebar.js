import { ref } from 'vue';

// Шторка боковой панели на мобильном: открывает кнопка ☰ в верхней строке страницы
const open = ref(false);

export function useSidebar() {
    return open;
}
