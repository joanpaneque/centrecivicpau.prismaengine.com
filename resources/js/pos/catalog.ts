import { computed } from 'vue';
import { tr } from '@/i18n';
import { sorted, state } from './store';
import type { ModifierGroup, Product, SetMenu } from './types';

export function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

export function categoryDestination(categoryId: number | null): number | null {
    let category = categoryId ? state.categories[categoryId] : undefined;
    let guard = 0;

    while (category && guard++ < 10) {
        if (category.destinationId) {
            return category.destinationId;
        }

        category = category.parentId ? state.categories[category.parentId] : undefined;
    }

    return null;
}

export function productDestination(product: Product): number | null {
    return product.destinationId ?? categoryDestination(product.categoryId);
}

export function productColor(product: Product): string | null {
    if (product.color) {
        return product.color;
    }

    let category = product.categoryId ? state.categories[product.categoryId] : undefined;
    let guard = 0;

    while (category && guard++ < 10) {
        if (category.color) {
            return category.color;
        }

        category = category.parentId ? state.categories[category.parentId] : undefined;
    }

    return null;
}

export function productModifierGroups(product: Product): ModifierGroup[] {
    const ids = new Set(product.modifierGroupIds);
    let category = product.categoryId ? state.categories[product.categoryId] : undefined;
    let guard = 0;

    while (category && guard++ < 10) {
        category.modifierGroupIds.forEach((id) => ids.add(id));
        category = category.parentId ? state.categories[category.parentId] : undefined;
    }

    return [...ids]
        .map((id) => state.modifierGroups[id])
        .filter((g): g is ModifierGroup => !!g && !g.deleted && g.modifiers.length > 0)
        .sort((a, b) => a.sort - b.sort);
}

function localDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function isMenuAvailable(menu: SetMenu, date = new Date()): boolean {
    if (!menu.active || menu.deleted) {
        return false;
    }

    if (menu.scheduleType === 'weekdays') {
        const isoDay = ((date.getDay() + 6) % 7) + 1;

        return menu.weekdays.includes(isoDay);
    }

    if (menu.scheduleType === 'dates') {
        const day = localDate(date);

        return (!menu.startsOn || menu.startsOn <= day) && (!menu.endsOn || menu.endsOn >= day);
    }

    return true;
}

export const menusToday = computed(() =>
    Object.values(state.setMenus)
        .filter((m) => isMenuAvailable(m))
        .sort((a, b) => a.sort - b.sort),
);

export const frequentProducts = computed(() =>
    [...sorted.products.value]
        .filter((p) => p.orderCount > 0)
        .sort((a, b) => b.orderCount - a.orderCount)
        .slice(0, 18),
);

export function searchProducts(query: string): Product[] {
    const q = normalize(query.trim());

    if (!q) {
        return [];
    }

    return sorted.products.value.filter((p) => normalize(`${p.name.ca} ${p.name.es}`).includes(q)).slice(0, 60);
}

export function categoryProducts(categoryId: number): Product[] {
    const ids = new Set<number>([categoryId]);
    let added = true;

    while (added) {
        added = false;

        for (const category of Object.values(state.categories)) {
            if (category.parentId && ids.has(category.parentId) && !ids.has(category.id)) {
                ids.add(category.id);
                added = true;
            }
        }
    }

    return sorted.products.value.filter((p) => p.categoryId !== null && ids.has(p.categoryId)).sort((a, b) => b.orderCount - a.orderCount || a.sort - b.sort);
}

export function productName(product: Product): string {
    return tr(product.name);
}
