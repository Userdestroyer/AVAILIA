export const formatPrice = (value, currency) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);

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
