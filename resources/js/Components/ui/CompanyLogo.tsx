import type { Company } from '@/types/shared';
import { CompanyMark } from './CompanyMark';
import { cx } from './utils';

export interface CompanyLogoProps {
    company: Pick<Company, 'name' | 'short_name' | 'colors' | 'logo_url' | 'logo_dark_url'>;
    /** Logo height in px (default 20). */
    height?: number;
    className?: string;
}

/**
 * Company logo for the current scheme. Both images are in the DOM and CSS
 * (`dark:` variant: system preference or data-theme) shows one, so there is
 * no flash or JS. Falls back to the letter mark without a logo.
 */
export function CompanyLogo({ company, height = 20, className }: CompanyLogoProps) {
    if (!company.logo_url) {
        return <CompanyMark company={company} size={Math.max(24, height)} variant="solid" className={className} />;
    }

    const darkSrc = company.logo_dark_url ?? company.logo_url;
    const style = { height, width: 'auto' };

    return (
        <span className={cx('inline-flex shrink-0', className)}>
            <img src={company.logo_url} alt={company.name} style={style} className="block dark:hidden" />
            <img src={darkSrc} alt={company.name} style={style} className="hidden dark:block" />
        </span>
    );
}
