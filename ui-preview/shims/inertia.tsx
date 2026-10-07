/**
 * Backend-free stand-in for `@inertiajs/react`, aliased only in the preview
 * build. Pages are typechecked against the real package; this shim mirrors
 * the subset they use so they render without a server: navigation and form
 * submissions are logged instead of sent. Inside the gallery frame they are
 * also posted to the parent shell, which shows "Navegación simulada: …".
 */
import { useCallback, useEffect, useRef, useState, type AnchorHTMLAttributes, type MouseEvent, type ReactNode } from 'react';
import { navigationMessage } from '../lib/navigation';

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';
type VisitData = Record<string, unknown>;
type VisitOptions = Record<string, unknown>;

function log(action: string, ...details: unknown[]) {
    console.info(`[inertia-shim] ${action}`, ...details);
}

/** Tell the gallery shell (if any) that the page tried to navigate. Never leaves the page. */
function notifyParent(method: string, url: string) {
    if (typeof window === 'undefined' || window.parent === window) return;
    window.parent.postMessage(navigationMessage(method, url), '*');
}

// ---- router ---------------------------------------------------------------

function visit(url: string, options: VisitOptions = {}) {
    log('visit', url, options);
    notifyParent(typeof options.method === 'string' ? options.method : 'get', url);
}

export const router = {
    visit,
    get: (url: string, data?: VisitData, options?: VisitOptions) => visit(url, { method: 'get', data, ...options }),
    post: (url: string, data?: VisitData, options?: VisitOptions) => visit(url, { method: 'post', data, ...options }),
    put: (url: string, data?: VisitData, options?: VisitOptions) => visit(url, { method: 'put', data, ...options }),
    patch: (url: string, data?: VisitData, options?: VisitOptions) => visit(url, { method: 'patch', data, ...options }),
    delete: (url: string, options?: VisitOptions) => visit(url, { method: 'delete', ...options }),
    reload: (options?: VisitOptions) => log('reload', options ?? {}),
};

// ---- Link -----------------------------------------------------------------

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string;
    method?: Method;
    as?: 'a' | 'button';
    data?: VisitData;
    preserveScroll?: boolean;
    preserveState?: boolean;
    replace?: boolean;
    only?: string[];
    prefetch?: boolean;
    children?: ReactNode;
};

export function Link({
    href,
    method = 'get',
    as = 'a',
    data,
    preserveScroll: _preserveScroll,
    preserveState: _preserveState,
    replace: _replace,
    only: _only,
    prefetch: _prefetch,
    onClick,
    children,
    ...rest
}: LinkProps) {
    const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
        onClick?.(event);
        event.preventDefault();
        log('link', method.toUpperCase(), href, data ?? {});
        notifyParent(method, href);
    };

    if (as === 'button') {
        const { type: _type, ...buttonRest } = rest as Record<string, unknown>;
        return (
            <button
                type="button"
                {...(buttonRest as object)}
                onClick={(event) => {
                    event.preventDefault();
                    log('link', method.toUpperCase(), href, data ?? {});
                    notifyParent(method, href);
                }}
            >
                {children}
            </button>
        );
    }

    return (
        <a href={href} {...rest} onClick={handleClick}>
            {children}
        </a>
    );
}

// ---- Head -----------------------------------------------------------------

export function Head({ title, children: _children }: { title?: string; children?: ReactNode }) {
    useEffect(() => {
        if (title !== undefined) {
            document.title = title;
        }
    }, [title]);
    return null;
}

// ---- usePage --------------------------------------------------------------

export function usePage(): never {
    throw new Error(
        'usePage() is not available in ui-preview. Pages and components must receive shared props explicitly instead of reading them from usePage().',
    );
}

// ---- useForm --------------------------------------------------------------

type FormDataShape = Record<string, unknown>;
type Errors<T> = Partial<Record<keyof T | string, string>>;

interface SubmitOptions {
    onBefore?: () => void;
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    preserveScroll?: boolean;
    preserveState?: boolean;
}

export function useForm<T extends FormDataShape>(initialValues: T) {
    const defaults = useRef(initialValues);
    const transformer = useRef<(data: T) => object>((value) => value);
    const [data, setDataState] = useState<T>(initialValues);
    const [errors, setErrors] = useState<Errors<T>>({});
    const [processing, setProcessing] = useState(false);
    const [wasSuccessful, setWasSuccessful] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);

    function setData<K extends keyof T>(key: K, value: T[K]): void;
    function setData(values: T | ((previous: T) => T)): void;
    function setData(keyOrValues: keyof T | T | ((previous: T) => T), value?: unknown): void {
        if (typeof keyOrValues === 'function') {
            setDataState(keyOrValues as (previous: T) => T);
        } else if (typeof keyOrValues === 'object' && keyOrValues !== null) {
            setDataState(keyOrValues as T);
        } else {
            setDataState((previous) => ({ ...previous, [keyOrValues]: value }));
        }
    }

    function setError(field: keyof T | string, message: string): void;
    function setError(fields: Errors<T>): void;
    function setError(fieldOrFields: keyof T | string | Errors<T>, message?: string): void {
        if (typeof fieldOrFields === 'object') {
            setErrors((previous) => ({ ...previous, ...fieldOrFields }));
        } else {
            setErrors((previous) => ({ ...previous, [fieldOrFields]: message }));
        }
    }

    const clearErrors = useCallback((...fields: (keyof T | string)[]) => {
        if (fields.length === 0) {
            setErrors({});
            return;
        }
        setErrors((previous) => {
            const next = { ...previous };
            for (const field of fields) delete next[field];
            return next;
        });
    }, []);

    const reset = useCallback((...fields: (keyof T)[]) => {
        if (fields.length === 0) {
            setDataState(defaults.current);
            return;
        }
        setDataState((previous) => {
            const next = { ...previous };
            for (const field of fields) next[field] = defaults.current[field];
            return next;
        });
    }, []);

    const transform = useCallback((callback: (data: T) => object) => {
        transformer.current = callback;
    }, []);

    const submit = (method: Method, url: string, options: SubmitOptions = {}): Promise<void> => {
        options.onBefore?.();
        options.onStart?.();
        setProcessing(true);
        log(`form ${method.toUpperCase()}`, url, transformer.current(data));
        notifyParent(method, url);
        return Promise.resolve().then(() => {
            setProcessing(false);
            setWasSuccessful(true);
            setRecentlySuccessful(true);
            setTimeout(() => setRecentlySuccessful(false), 2000);
            options.onSuccess?.();
            options.onFinish?.();
        });
    };

    return {
        data,
        setData,
        errors,
        hasErrors: Object.keys(errors).length > 0,
        setError,
        clearErrors,
        processing,
        progress: null,
        wasSuccessful,
        recentlySuccessful,
        isDirty: JSON.stringify(data) !== JSON.stringify(defaults.current),
        reset,
        transform,
        setDefaults: (values?: T) => {
            defaults.current = values ?? data;
        },
        cancel: () => log('form cancel'),
        submit: (method: Method, url: string, options?: SubmitOptions) => submit(method, url, options),
        get: (url: string, options?: SubmitOptions) => submit('get', url, options),
        post: (url: string, options?: SubmitOptions) => submit('post', url, options),
        put: (url: string, options?: SubmitOptions) => submit('put', url, options),
        patch: (url: string, options?: SubmitOptions) => submit('patch', url, options),
        delete: (url: string, options?: SubmitOptions) => submit('delete', url, options),
    };
}
