/**
 * Product helpers (boards B-Products, B-ProductDetail): state chips, list
 * totals and wording. Costs stay null when the viewer lacks `ai.costs.view`.
 */
import type { IconName } from '../../ui/Icon';
import type { StatusFamily } from '../../../lib/status';
import { requestStatus, type RequestStatusKey } from '../../../lib/status';
import type { ProductState } from '../../../types/ai';

export const PRODUCT_STATES: Record<ProductState, { label: string; tone: StatusFamily; icon: IconName }> = {
    production: { label: 'Producción', tone: 'green', icon: 'signal' },
    pilot: { label: 'Piloto', tone: 'amber', icon: 'clipboard-check' },
    development: { label: 'En desarrollo', tone: 'blue', icon: 'code' },
    retired: { label: 'Retirado', tone: 'slate', icon: 'archive' },
};

export const PRODUCT_STATE_ORDER: ProductState[] = ['production', 'pilot', 'development', 'retired'];

interface TotalsLike {
    rating: { average: number; count: number } | null;
    hours_saved_per_month: number | null;
    monthly_cost: number | null;
    open_requests: number;
}

export interface ProductTotals {
    count: number;
    /** Average weighted by the number of ratings; null without ratings. */
    rating: number | null;
    ratings: number;
    hours: number;
    /** Null when any cost is hidden (viewer without `ai.costs.view`). */
    cost: number | null;
    open: number;
}

export function productTotals(products: readonly TotalsLike[]): ProductTotals {
    let weighted = 0;
    let ratings = 0;
    let hours = 0;
    let cost: number | null = 0;
    let open = 0;
    for (const product of products) {
        if (product.rating) {
            weighted += product.rating.average * product.rating.count;
            ratings += product.rating.count;
        }
        hours += product.hours_saved_per_month ?? 0;
        cost = cost === null || product.monthly_cost === null ? null : cost + product.monthly_cost;
        open += product.open_requests;
    }
    return { count: products.length, rating: ratings > 0 ? weighted / ratings : null, ratings, hours, cost, open };
}

/** "Cartera", "Cartera y Ventas", "A, B y C". */
export function joinWords(words: readonly string[]): string {
    if (words.length <= 1) return words[0] ?? '';
    return `${words.slice(0, -1).join(', ')} y ${words[words.length - 1]}`;
}

const decimal = (value: number) => value.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

/** "4,6 / 5". */
export function ratingText(average: number): string {
    return `${decimal(average)} / 5`;
}

/** "Ninguna", "1 abierta", "3 abiertas". */
export function openRequestsText(count: number): string {
    if (count === 0) return 'Ninguna';
    return `${count} ${count === 1 ? 'abierta' : 'abiertas'}`;
}

/** Status labels in lower case, pluralised by adding "s" to the last word when needed. */
function statusWords(status: RequestStatusKey, count: number): string {
    const label = requestStatus(status).label.toLowerCase();
    if (count === 1 || label.startsWith('en ')) return label;
    return label.replace(/(\S+)$/, (word) => (/[aeiou]$/.test(word) ? `${word}s` : word));
}

/** "1 en desarrollo · 2 recibidas" in first-seen order. */
export function requestsBreakdown(requests: readonly { status: RequestStatusKey }[]): string {
    if (requests.length === 0) return 'Ninguna abierta';
    const counts = new Map<RequestStatusKey, number>();
    for (const request of requests) counts.set(request.status, (counts.get(request.status) ?? 0) + 1);
    return [...counts].map(([status, count]) => `${count} ${statusWords(status, count)}`).join(' · ');
}
