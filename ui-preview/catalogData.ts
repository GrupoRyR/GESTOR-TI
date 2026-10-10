/**
 * Discovery for the gallery shell and the frame. No manual registry: every
 * page file and every mock variant is found by glob. Pages load lazily;
 * mocks are small and load eagerly so the shell can show their props.
 */
import type { ComponentType } from 'react';
import { ANDINA, DERIVADOS, SEGUREX } from '@/mocks/shared';
import samples from '../resources/emails/samples.json';
import { buildCatalog, pageNameFromPath } from './lib/catalog';

export type PageModule = { default: ComponentType<Record<string, unknown>> };

const pageLoaders = import.meta.glob<PageModule>('@/Pages/**/*.tsx');
const mockModules = import.meta.glob('@/mocks/*.ts', { eager: true });

/** Email templates (labels only; the shell loads the files itself). */
const emailTemplates = Object.entries(samples.templates).map(([key, entry]) => ({ key, label: entry.label }));

export const catalog = buildCatalog(Object.keys(pageLoaders), mockModules, emailTemplates);

/** Inertia component name → lazy module loader. */
export const pageLoaderByName = new Map<string, () => Promise<PageModule>>(
    Object.entries(pageLoaders).flatMap(([path, loader]) => {
        const name = pageNameFromPath(path);
        return name === null ? [] : [[name, loader] as const];
    }),
);

/** Companies the brand override can switch to. */
export const BRAND_COMPANIES = [SEGUREX, DERIVADOS, ANDINA];
