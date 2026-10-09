import { route } from '@/lib/route';
import type { AttentionItem, HomeAttention } from '@/types/pages/home';

export type AttentionKey = keyof HomeAttention;

/** Order and titles of the four attention groups (as in the approved canvas). */
export const ATTENTION_GROUPS: { key: AttentionKey; title: string; empty: string }[] = [
    { key: 'unanswered_requests', title: 'Sin primera respuesta', empty: 'Todas tienen respuesta.' },
    { key: 'overdue_tasks', title: 'Tareas vencidas', empty: 'Ninguna tarea vencida.' },
    { key: 'waiting_requirements', title: 'Esperando a usuarios', empty: 'Nada pendiente de usuarios.' },
    { key: 'expiring_credentials', title: 'Credenciales por vencer', empty: 'Ninguna por vencer.' },
];

/** Where a phone row goes when its group holds several items. */
const GROUP_ROUTES: Record<AttentionKey, [string, Record<string, string>?]> = {
    unanswered_requests: ['ai.requests.index', { attention: 'unanswered' }],
    overdue_tasks: ['ai.requests.index', { attention: 'overdue_tasks' }],
    waiting_requirements: ['ai.requests.index', { attention: 'waiting_requirements' }],
    expiring_credentials: ['ai.vault.index'],
};

export function itemHref(item: AttentionItem): string {
    return route(item.href_route, item.href_params);
}

/** One item: straight to it. Several: to the filtered list. */
export function groupHref(key: AttentionKey, items: AttentionItem[]): string {
    const [first] = items;
    if (items.length === 1 && first) return itemHref(first);
    const [name, params] = GROUP_ROUTES[key];
    return route(name, params);
}

/** Red when anything is late, amber otherwise, muted when empty. */
export function countTone(severities: ('late' | 'attention')[]): string {
    if (severities.length === 0) return 'text-fg-muted';
    return severities.includes('late') ? 'text-status-failure' : 'text-status-maintenance';
}

export function attentionTotal(attention: HomeAttention): number {
    return ATTENTION_GROUPS.reduce((total, group) => total + attention[group.key].length, 0);
}
