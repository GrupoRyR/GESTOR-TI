/**
 * Brand override for the gallery: on app pages (those with shared props and a
 * signed-in user) swap `current_company_id` for the chosen company so the
 * layout paints that brand. Guest pages have no selected company and are
 * returned untouched.
 */

interface CompanyLike {
    id: number;
    slug: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

export function isGuestProps(props: unknown): boolean {
    if (!isRecord(props) || !('current_company_id' in props)) return true;
    const auth = props.auth;
    return !isRecord(auth) || auth.user === null || auth.user === undefined;
}

/** `brand` is a company slug, or `example` to keep the mock as written. */
export function applyBrandOverride<P>(props: P, brand: string, companies: readonly CompanyLike[]): P {
    if (brand === 'example' || isGuestProps(props)) return props;
    const company = companies.find((candidate) => candidate.slug === brand);
    if (company === undefined) return props;

    const record = props as Record<string, unknown>;
    const listed = Array.isArray(record.companies) ? (record.companies as CompanyLike[]) : [];
    const visible = listed.some((candidate) => candidate.id === company.id) ? listed : [...listed, company];
    return { ...record, companies: visible, current_company_id: company.id } as P;
}
