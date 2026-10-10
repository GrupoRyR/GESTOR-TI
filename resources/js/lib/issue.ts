/**
 * GitHub issue text created from a task or an approved request ("Crear
 * issue en GitHub"). The server sends exactly this title/body; it never
 * adds hours, costs, internal notes or vault data.
 */
import { normalizeRequestKey } from './githubKeys';
import { slugify } from './slug';

/** Label every issue created from the Gestor carries. */
export const DEFAULT_ISSUE_LABEL = 'gestor-ia';

/** GitHub's limit for issue titles. */
export const ISSUE_TITLE_MAX = 256;

export const ISSUE_FOOTER = 'No incluye costos ni notas internas.';

export interface IssueBodyInput {
    description: string;
    criteria: string[];
    /** Link back to the task or request in the Gestor. */
    link: { label: string; url: string };
    requestKey: string;
}

/** "GIA-139 · T4 Consulta de cartera por asesor desde SAP B1". */
export function issueTitle(requestKey: string, task: { key: string; title: string } | null, requestTitle?: string): string {
    const key = normalizeRequestKey(requestKey) ?? requestKey.trim();
    const rest = task ? `${task.key} ${task.title.trim()}` : (requestTitle ?? '').trim();
    const title = rest ? `${key} · ${rest}` : key;
    return title.length > ISSUE_TITLE_MAX ? `${title.slice(0, ISSUE_TITLE_MAX - 1).trimEnd()}…` : title;
}

/** Description, criteria checklist, link back and the privacy footer. */
export function issueBody({ description, criteria, link, requestKey }: IssueBodyInput): string {
    const blocks: string[] = [];
    if (description.trim()) blocks.push(description.trim());

    const items = criteria.map((criterion) => criterion.trim()).filter(Boolean);
    if (items.length > 0) {
        blocks.push('## Criterios de aceptación', items.map((item) => `- [ ] ${item}`).join('\n'));
    }

    const key = normalizeRequestKey(requestKey) ?? requestKey.trim();
    blocks.push('---', `Creado desde Gestor TI · [${link.label}](${link.url}) · solicitud ${key}\n\n${ISSUE_FOOTER}`);
    return `${blocks.join('\n\n')}\n`;
}

/** `gestor-ia` first, then the task labels as slugs ("SAP" → "sap"), without repeats. */
export function defaultIssueLabels(taskLabels: string[] = []): string[] {
    const labels = [DEFAULT_ISSUE_LABEL];
    for (const label of taskLabels) {
        const slug = slugify(label);
        if (slug && !labels.includes(slug)) labels.push(slug);
    }
    return labels;
}
