import { Button } from '@/Components/ui/Button';
import { IconButton } from '@/Components/ui/IconButton';

export interface BriefTriggerProps {
    expanded: boolean;
    onOpen: () => void;
    /** `icon`: square button for drawer headers ("Brief para Claude" as its label). */
    compact?: boolean;
    className?: string;
}

/** "Brief para Claude": opens the BriefPanel; outlined dark while the panel is open (D-BriefRequest). */
export function BriefTrigger({ expanded, onOpen, compact = false, className }: BriefTriggerProps) {
    if (compact) {
        return <IconButton icon="code" label="Brief para Claude" aria-haspopup="dialog" aria-expanded={expanded} onClick={onOpen} className={className} />;
    }
    return (
        <Button
            variant="secondary"
            icon="code"
            aria-haspopup="dialog"
            aria-expanded={expanded}
            onClick={onOpen}
            className={['border-2 border-fg', className].filter(Boolean).join(' ')}
        >
            Brief para Claude
        </Button>
    );
}
