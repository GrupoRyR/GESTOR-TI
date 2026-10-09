/**
 * Documentation helpers (boards B-Docs, B-DocsTemplates, B-DocsHistory):
 * the template catalog, the doc tree grouping, the autosave line and the
 * diff caption. The server builds each new document from the same section
 * list (`DOC_TEMPLATES[].sections`).
 */
import type { IconName } from '../../ui/Icon';
import { formatRelative } from '../../../lib/format';
import type { DocTemplate } from '../../../types/ai';

export interface DocTemplateInfo {
    key: DocTemplate;
    name: string;
    description: string;
    sections: string[];
    icon: IconName;
    /** Shown on the card, e.g. the vault reminder on "Arquitectura y datos". */
    warning?: string;
}

/** The eight approved templates, in the order of the picker. */
export const DOC_TEMPLATES: DocTemplateInfo[] = [
    {
        key: 'project_sheet',
        name: 'Ficha del proyecto',
        description: 'La hoja de vida del proyecto: para qué es, quién lo pidió y en qué va.',
        sections: ['Objetivo', 'Alcance', 'Responsables', 'Fechas', 'Presupuesto', 'Estado'],
        icon: 'clipboard-check',
    },
    {
        key: 'requirements',
        name: 'Requerimientos y criterios',
        description: 'Lo que la app debe hacer y cómo se comprueba que quedó bien.',
        sections: ['Historias', 'Criterios de aceptación', 'Fuera de alcance', 'Preguntas abiertas'],
        icon: 'subtasks',
    },
    {
        key: 'architecture',
        name: 'Arquitectura y datos',
        description: 'Cómo está armada la app y de dónde saca la información.',
        sections: ['Diagrama', 'Fuentes de datos', 'Integraciones', 'Variables de entorno (sin valores)'],
        icon: 'building',
        warning: 'Nunca escribas contraseñas aquí; van en la bóveda.',
    },
    {
        key: 'prompts',
        name: 'Prompts y modelos',
        description: 'Qué modelo de IA usa, con qué instrucciones y cuánto cuesta.',
        sections: ['Modelo y proveedor', 'Prompts con versión', 'Pruebas', 'Costo por uso'],
        icon: 'message',
    },
    {
        key: 'decisions',
        name: 'Registro de decisiones',
        description: 'Por qué se eligió una opción y no otra, para no repetir la discusión.',
        sections: ['Contexto', 'Opciones', 'Decisión', 'Consecuencias', 'Fecha y quién'],
        icon: 'signal',
    },
    {
        key: 'user_manual',
        name: 'Manual de usuario',
        description: 'Para quien usa la app: cómo entrar y hacer cada tarea, paso a paso.',
        sections: ['Para qué sirve', 'Cómo entrar', 'Tareas paso a paso', 'Preguntas frecuentes', 'Pedir mejora'],
        icon: 'list-ordered',
    },
    {
        key: 'technical_manual',
        name: 'Manual técnico y de despliegue',
        description: 'Para quien la mantiene: cómo instalar, publicar y recuperar la app.',
        sections: ['Requisitos', 'Instalación', 'Publicar una versión', 'Respaldos', 'Qué hacer si se cae'],
        icon: 'code',
    },
    {
        key: 'delivery_record',
        name: 'Acta de entrega',
        description: 'La constancia de que el área recibió la app y está conforme.',
        sections: ['Qué se entrega', 'Criterios cumplidos', 'Pendientes', 'Firmas del área y de TI'],
        icon: 'check-circle',
    },
];

export function templateName(key: DocTemplate): string {
    return DOC_TEMPLATES.find((template) => template.key === key)?.name ?? 'En blanco';
}

export interface DocOwnerLike {
    kind: 'project' | 'product';
    key: string;
    name: string;
}

/** Docs grouped by their project or product, in first-seen order. */
export function groupDocs<D extends { owner: DocOwnerLike }>(docs: readonly D[]): { owner: DocOwnerLike; docs: D[] }[] {
    const groups = new Map<string, { owner: DocOwnerLike; docs: D[] }>();
    for (const doc of docs) {
        const id = `${doc.owner.kind}:${doc.owner.key}`;
        const group = groups.get(id) ?? { owner: doc.owner, docs: [] };
        group.docs.push(doc);
        groups.set(id, group);
    }
    return [...groups.values()];
}

export type AutosaveState = 'saved' | 'dirty' | 'saving' | 'error';

/** "Guardado hace 1 min · versión 7", "Guardando…", "Cambios sin guardar". */
export function autosaveText({ state, savedAt, version, now }: { state: AutosaveState; savedAt: string | null; version: number; now: Date }): string {
    if (state === 'saving') return 'Guardando…';
    if (state === 'dirty') return 'Cambios sin guardar';
    if (state === 'error') return 'No se pudo guardar: tus cambios siguen aquí';
    const when = savedAt ? ` ${formatRelative(savedAt, now)}` : '';
    return `Guardado${when} · versión ${version}`;
}

/** Caption of the diff: "Versión 6 comparada con la actual (7)". */
export function compareLabel(version: number, against: number, current: number): string {
    const name = version === current ? `Versión ${version} (actual)` : `Versión ${version}`;
    const other = against === current ? `la actual (${against})` : `la ${against}`;
    return `${name} comparada con ${other}`;
}
