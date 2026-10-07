import { useEffect, useState } from 'react';
import type { Flash } from '@/types/shared';
import { Alert } from './Alert';
import { cx } from './utils';

export interface FlashMessagesProps {
    flash: Flash;
    className?: string;
}

/** Server flash messages (`flash.success`, `flash.error`) as dismissible banners. */
export function FlashMessages({ flash, className }: FlashMessagesProps) {
    const [dismissed, setDismissed] = useState<{ success?: string; error?: string }>({});

    // A new message (next visit) shows again even if the previous one was closed.
    useEffect(() => {
        setDismissed({});
    }, [flash.success, flash.error]);

    const showSuccess = flash.success && dismissed.success !== flash.success;
    const showError = flash.error && dismissed.error !== flash.error;

    if (!showSuccess && !showError) return null;

    return (
        <div className={cx('flex flex-col gap-2', className)}>
            {showError ? (
                <Alert tone="danger" onDismiss={() => setDismissed((d) => ({ ...d, error: flash.error }))}>
                    <span className="text-fg">{flash.error}</span>
                </Alert>
            ) : null}
            {showSuccess ? (
                <Alert tone="success" onDismiss={() => setDismissed((d) => ({ ...d, success: flash.success }))}>
                    <span className="text-fg">{flash.success}</span>
                </Alert>
            ) : null}
        </div>
    );
}
