import { onBeforeUnmount, ref } from 'vue';

/**
 * Реактивное совпадение media query — для раскладок, которые на узких экранах
 * становятся всплывающими (шторка, панель настроек).
 */
export function useMediaQuery(query) {
    const media = window.matchMedia(query);
    const matches = ref(media.matches);
    const update = (event) => (matches.value = event.matches);

    media.addEventListener('change', update);
    onBeforeUnmount(() => media.removeEventListener('change', update));

    return matches;
}
