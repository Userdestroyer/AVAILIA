import { computed, reactive, ref } from 'vue';
import { deleteJson, getJson, patchJson, postJson } from '@/lib/http';

/**
 * Картриджи «Обо мне» для редактора в модалке профиля. Живут на уровне приложения:
 * грузятся заранее (модалка открывается мгновенно), а несохранённые черновики переживают
 * закрытие модалки. Сохраняет человек явно — кнопкой; каждое сохранение содержимого — версия.
 */
const FIELDS = ['title', 'content', 'is_pinned', 'tags'];

const plates = ref([]);
const knownTags = ref([]);
const loaded = ref(false);
let loading = null;
let localSeq = 0;

// Черновик — копия сохранённого; «изменён» — если отличается хоть одним полем
const draftOf = (plate) => ({ title: plate.title, content: plate.content, is_pinned: plate.is_pinned, tags: [...plate.tags] });
const same = (a, b) => FIELDS.every((key) => JSON.stringify(a[key]) === JSON.stringify(b[key]));

const wrap = (saved) => reactive({ uid: `s${saved.id}`, saved, draft: draftOf(saved), status: null });

export function useContextPlates() {
    const load = () => (loading ??= getJson('/context/plates')
        .then((data) => {
            plates.value = data.plates.map(wrap);
            knownTags.value = data.tags;
            loaded.value = true;
        })
        .catch(() => (loading = null)));

    const isDirty = (plate) => !plate.saved || !same(plate.draft, plate.saved);
    const dirtyCount = computed(() => plates.value.filter(isDirty).length);

    // Новый картридж — сразу черновиком в списке; на сервер попадёт по «Сохранить»
    const create = (starter = { title: 'Новый картридж', tags: [] }) => {
        const plate = reactive({ uid: `local-${++localSeq}`, saved: null, draft: { title: starter.title, content: '', is_pinned: false, tags: [...starter.tags] }, status: null });
        plates.value.push(plate);
        return plate;
    };

    // Сохранение оптимистичное: сразу «сохранено», не принял сервер — черновик снова изменён и видна ошибка
    const save = async (plate) => {
        if (!isDirty(plate)) return false;
        const previous = plate.saved;
        const draft = { ...plate.draft, tags: [...plate.draft.tags] };
        plate.saved = { ...(previous ?? {}), ...draft };
        plate.status = null;

        try {
            const { plate: saved } = previous?.id
                ? await patchJson(`/context/plates/${previous.id}`, draft)
                : await postJson('/context/plates', draft);
            plate.saved = saved;
            // Пока шёл запрос, человек мог продолжить править — его черновик не трогаем
            if (same(plate.draft, draft)) plate.draft = draftOf(saved);
            knownTags.value = [...new Set([...knownTags.value, ...saved.tags])];
            return true;
        } catch (e) {
            plate.saved = previous;
            plate.status = e.message;
            return false;
        }
    };

    const revert = (plate) => {
        if (plate.saved) plate.draft = draftOf(plate.saved);
        else plates.value = plates.value.filter((p) => p !== plate);
    };

    const remove = async (plate) => {
        const index = plates.value.indexOf(plate);
        plates.value.splice(index, 1);
        if (!plate.saved?.id) return true;
        try {
            await deleteJson(`/context/plates/${plate.saved.id}`);
            return true;
        } catch (e) {
            plates.value.splice(index, 0, plate);
            plate.status = e.message;
            return false;
        }
    };

    return { plates, knownTags, loaded, load, isDirty, dirtyCount, create, save, revert, remove };
}
