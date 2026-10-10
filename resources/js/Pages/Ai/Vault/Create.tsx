import { VaultItemForm } from '@/Components/ai/vault/VaultItemForm';
import { GestorLayout } from '@/Layouts/GestorLayout';
import type { VaultCreateProps } from '@/types/pages/ai-value-vault';
import type { PageProps } from '@/types/shared';

/**
 * "Nueva credencial": the C-VaultItemForm without the delete zone and with
 * the value required. Same guards as Edit (`ai.vault.access`, open session,
 * `Inertia::encryptHistory()`, no-store).
 */
export default function VaultCreate(props: PageProps<VaultCreateProps>) {
    return (
        <GestorLayout shared={props} title="Nueva credencial · Bóveda" tab="vault" mode="it">
            <VaultItemForm {...props} mode="create" />
        </GestorLayout>
    );
}
