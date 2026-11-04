import { ref } from 'vue';

// Общее на всё приложение состояние «модель думает» — его слушает фон
const thinking = ref(false);

export function useThinking() {
    return thinking;
}
