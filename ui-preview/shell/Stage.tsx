import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { fitScale, VIEWPORTS, type Size, type Viewport } from '../lib/hashState';

const PADDING = 24;

function useElementSize<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [size, setSize] = useState<Size>({ width: 0, height: 0 });
    useLayoutEffect(() => {
        const element = ref.current;
        if (element === null) return;
        const observer = new ResizeObserver(([entry]) => {
            if (entry === undefined) return;
            setSize({ width: entry.contentRect.width, height: entry.contentRect.height });
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    return [ref, size] as const;
}

interface StageProps {
    viewport: Viewport;
    /** `frame.html#…` for the current selection. */
    frameHash: string;
    title: string;
}

/**
 * The device canvas: an iframe at the device's real CSS size (so breakpoints
 * apply), scaled down with a transform when it does not fit. The iframe is
 * created once; later selections only change its hash, so it never reloads.
 */
export function Stage({ viewport, frameHash, title }: StageProps) {
    const [areaRef, area] = useElementSize<HTMLDivElement>();
    const frameRef = useRef<HTMLIFrameElement>(null);
    const [initialSrc] = useState(() => `frame.html#${frameHash}`);
    const latestHash = useRef(frameHash);
    latestHash.current = frameHash;

    // Change only the frame's hash (no reload, no history entry). Before the
    // frame document has loaded its location is about:blank, so wait for onLoad.
    const syncFrame = useCallback(() => {
        const iframe = frameRef.current;
        const wanted = new URL(`frame.html#${latestHash.current}`, document.baseURI);
        try {
            const location = iframe?.contentWindow?.location;
            if (location === undefined || location.href === wanted.href) return;
            if (location.pathname === wanted.pathname) location.replace(wanted.href);
        } catch {
            iframe?.setAttribute('src', wanted.href);
        }
    }, []);

    useEffect(syncFrame, [frameHash, syncFrame]);

    const device = VIEWPORTS[viewport].size;
    const available = { width: Math.max(0, area.width - PADDING * 2), height: Math.max(0, area.height - PADDING * 2) };
    const frame = device ?? { width: Math.max(320, area.width), height: Math.max(320, area.height) };
    const scale = device === null ? 1 : fitScale(device, available);

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col">
            <div
                ref={areaRef}
                className="gallery-stage relative min-h-0 flex-1 overflow-hidden"
                style={device === null ? undefined : { padding: PADDING }}
            >
                <div
                    className={device === null ? 'absolute inset-0' : 'relative mx-auto'}
                    style={device === null ? undefined : { width: frame.width * scale, height: frame.height * scale }}
                >
                    <iframe
                        ref={frameRef}
                        src={initialSrc}
                        onLoad={syncFrame}
                        title={title}
                        className={
                            device === null
                                ? 'block size-full border-0 bg-ground'
                                : 'absolute top-0 left-0 origin-top-left rounded-[14px] border-0 bg-ground shadow-[0_0_0_1px_var(--color-line-strong),0_24px_48px_-24px_rgb(23_25_28/0.45)]'
                        }
                        style={
                            device === null
                                ? undefined
                                : { width: frame.width, height: frame.height, transform: `scale(${scale})` }
                        }
                    />
                </div>
            </div>
            <p className="tabular pointer-events-none absolute right-3 bottom-2 rounded-chip bg-surface/90 px-2 py-0.5 text-[12px] font-semibold text-fg-muted">
                {Math.round(frame.width)} × {Math.round(frame.height)} px · {Math.round(scale * 100)} %
            </p>
        </div>
    );
}
