import { VaultItemForm } from '@/Components/ai/vault/VaultItemForm';
import { GestorLayout } from '@/Layouts/GestorLayout';
import type { VaultEditProps } from '@/types/pages/ai-value-vault';
import type { PageProps } from '@/types/shared';

/**
 * C-VaultItemForm: edit a credential inside the dark protected zone. Only
 * reachable with `ai.vault.access` and an open vault session (the server
 * redirects to the locked `ai.vault.index` otherwise). The current value is
 * never sent; the response uses `Inertia::encryptHistory()` and no-store.
 */
export default function VaultEdit(props: PageProps<VaultEditProps>) {
    return (
        <GestorLayout shared={props} title={`Editar ${props.item.name} · Bóveda`} tab="vault" mode="it">
            <VaultItemForm {...props} mode="edit" />
        </GestorLayout>
    );
}
