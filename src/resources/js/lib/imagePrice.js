/**
 * Оценка цены картинки, ₽ — как App\Services\Images\ImagePrice: за запрос или самой точной подходящей ступенью.
 * null — прайс не за картинку (сервер удержит запасную оценку).
 */
export function estimateImage(pricing, params, references = 0) {
    if (!pricing) return null;
    if (pricing.per_request !== undefined) return Number(pricing.per_request);

    const facts = { ...params, input_images_count: String(references) };
    let best = null;
    for (const tier of pricing.tiers ?? []) {
        const conditions = tier.conditions ?? [];
        const matches = conditions.every((condition) => {
            const [key, value] = condition.split('=');
            return String(facts[key] ?? '') === value;
        });
        if (matches && (!best || conditions.length > (best.conditions ?? []).length)) best = tier;
    }
    return best ? Number(best.cost_rub) : null;
}
