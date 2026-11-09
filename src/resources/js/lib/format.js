export const formatPrice = (value, currency) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);

// Энергия ⚡ — единица трат для юзера; дробная часть важна у коротких ответов
export const formatEnergy = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: Number(value) < 100 ? 1 : 0 }).format(Number(value));

// Копейки важны: короткий ответ может стоить доли рубля
export const formatCost = ({ amount, currency }) => {
    const value = Number(amount);
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: value < 1 ? 4 : 2,
    }).format(value);
};
