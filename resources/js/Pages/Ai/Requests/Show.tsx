import { ItRequestView } from '@/Components/ai/requests/ItRequestView';
import { RequesterRequestView } from '@/Components/ai/requests/RequesterRequestView';
import type { RequestsShowProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

/**
 * Request detail. The server picks the view: `requester` (the person who
 * asked, voters, followers) or `it` for holders of `ai.manage` (evaluation,
 * internal notes and actions).
 */
export default function RequestsShow(props: PageProps<RequestsShowProps>) {
    switch (props.view) {
        case 'requester':
            return <RequesterRequestView {...props} />;
        case 'it':
            return <ItRequestView {...props} />;
    }
}
