/**
 * Options of the "Vincular repositorio" dialog. A repository already linked
 * to another product or project stays visible but disabled ("Vinculado a
 * …"); a project may reuse the repository of its own product. The server
 * applies the same rule when it stores the link.
 */
import type { GitHubRepo } from '../types/github';

export interface RepoLinkTarget {
    kind: 'product' | 'project';
    key: string;
    /** For projects: the product they belong to, whose repo they may share. */
    product_key?: string | null;
}

export interface RepoLinkOption {
    repo: GitHubRepo;
    disabled: boolean;
    /** "Vinculado a Visualizador de cartera", "Del producto Toma Pedidos SAP", "Vinculado aquí"; null when free. */
    note: string | null;
}

const fold = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();

function optionFor(repo: GitHubRepo, target: RepoLinkTarget): RepoLinkOption {
    const linked = repo.linked_to;
    if (!linked) return { repo, disabled: false, note: null };
    if (linked.kind === target.kind && linked.key === target.key) return { repo, disabled: false, note: 'Vinculado aquí' };
    if (target.kind === 'project' && linked.kind === 'product' && target.product_key && linked.key === target.product_key) {
        return { repo, disabled: false, note: `Del producto ${linked.name}` };
    }
    return { repo, disabled: true, note: `Vinculado a ${linked.name}` };
}

/** Repositories in server order, filtered by name or language (case and accents ignored). */
export function repoLinkOptions(repos: GitHubRepo[], target: RepoLinkTarget, query = ''): RepoLinkOption[] {
    const needle = fold(query);
    return repos
        .filter((repo) => !needle || fold(repo.full_name).includes(needle) || fold(repo.language ?? '').includes(needle))
        .map((repo) => optionFor(repo, target));
}
