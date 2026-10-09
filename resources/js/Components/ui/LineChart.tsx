import { useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { linearScale, nearestIndex, niceTicks } from '@/lib/chart';
import { Icon } from './Icon';
import { cx } from './utils';

export type SeriesColor = 1 | 2 | 3 | 'muted';

export interface LineSeries {
    key: string;
    label: string;
    points: { x: number; y: number }[];
    /** Categorical slot (viz tokens) or `muted` for a reference line such as a plan. */
    color?: SeriesColor;
    /** Dashed only for projections, never for real data. */
    dashed?: boolean;
    /** 10 % wash under the line (use on one series at most). */
    area?: boolean;
}

export interface LineChartProps {
    /** Accessible name and table caption: "Horas ahorradas por mes". */
    title: string;
    /** One-sentence summary read before the chart. */
    summary?: string;
    series: LineSeries[];
    /** Labelled x positions (months, dates). */
    xTicks: { x: number; label: string }[];
    /** Full x label for tooltip and table: "Agosto 2026". */
    formatX: (x: number) => string;
    /** Axis ticks, tooltip and table values: "186 h". */
    formatY: (y: number) => string;
    /** Plot height in px, axis band excluded (default 240). */
    height?: number;
    /** Lowest y on the axis (default 0). */
    yMin?: number;
    /** Label the last point of the first series (default true). */
    endLabel?: boolean;
    /** Horizontal reference lines on the same axis, e.g. the 80 % and 100 % of the approved hours. */
    references?: { y: number; label: string }[];
    className?: string;
}

const STROKE: Record<SeriesColor, string> = {
    1: 'stroke-viz-1',
    2: 'stroke-viz-2',
    3: 'stroke-viz-3',
    muted: 'stroke-fg-muted',
};

const FILL: Record<SeriesColor, string> = {
    1: 'fill-viz-1',
    2: 'fill-viz-2',
    3: 'fill-viz-3',
    muted: 'fill-fg-muted',
};

const BG: Record<SeriesColor, string> = {
    1: 'bg-viz-1',
    2: 'bg-viz-2',
    3: 'bg-viz-3',
    muted: 'bg-fg-muted',
};

const MARGIN = { top: 14, right: 56, bottom: 30, left: 48 };

function useWidth<T extends HTMLElement>(fallback: number) {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(fallback);
    useLayoutEffect(() => {
        const element = ref.current;
        if (!element || typeof ResizeObserver === 'undefined') return;
        setWidth(Math.max(240, Math.round(element.getBoundingClientRect().width)));
        const observer = new ResizeObserver(([entry]) => {
            if (entry) setWidth(Math.max(240, Math.round(entry.contentRect.width)));
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    return { ref, width };
}

function LineKey({ color, dashed }: { color: SeriesColor; dashed?: boolean }) {
    return dashed ? (
        <svg aria-hidden="true" width="16" height="4" className="shrink-0">
            <path d="M0 2H16" strokeWidth="2" strokeDasharray="4 3" className={STROKE[color]} />
        </svg>
    ) : (
        <span aria-hidden="true" className={cx('h-0.5 w-4 shrink-0 rounded-full', BG[color])} />
    );
}

/**
 * SVG line chart: 2 px lines, hairline grid on one y axis, crosshair that
 * snaps to the nearest x with a tooltip listing every series (also with the
 * keyboard: focus the chart and use ← →), and a "Ver tabla" twin.
 */
export function LineChart({
    title,
    summary,
    series,
    xTicks,
    formatX,
    formatY,
    height = 240,
    yMin = 0,
    endLabel = true,
    references = [],
    className,
}: LineChartProps) {
    const { ref, width } = useWidth<HTMLDivElement>(640);
    const [showTable, setShowTable] = useState(false);
    const [active, setActive] = useState<number | null>(null);
    const summaryId = useId();

    const xs = useMemo(
        () => [...new Set(series.flatMap((line) => line.points.map((point) => point.x)))].sort((a, b) => a - b),
        [series],
    );
    const yMax = Math.max(yMin, ...series.flatMap((line) => line.points.map((point) => point.y)), ...references.map((reference) => reference.y));
    const ticks = niceTicks(yMin, yMax, 5);
    const xMin = Math.min(...xs, ...xTicks.map((tick) => tick.x));
    const xMax = Math.max(...xs, ...xTicks.map((tick) => tick.x));

    const plotWidth = width - MARGIN.left - MARGIN.right;
    const x = linearScale([xMin, xMax], [MARGIN.left, MARGIN.left + plotWidth]);
    const y = linearScale([ticks[0]!, ticks[ticks.length - 1]!], [MARGIN.top + height, MARGIN.top]);
    const positions = xs.map(x);

    const valueAt = (line: LineSeries, at: number) => line.points.find((point) => point.x === at)?.y;
    const activeX = active !== null ? xs[active] : undefined;

    const onPointerMove = (event: PointerEvent<SVGSVGElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        setActive(nearestIndex(positions, event.clientX - box.left));
    };

    const onKeyDown = (event: KeyboardEvent<SVGSVGElement>) => {
        const last = xs.length - 1;
        const current = active ?? last;
        const next =
            event.key === 'ArrowRight' ? Math.min(last, current + 1)
            : event.key === 'ArrowLeft' ? Math.max(0, current - 1)
            : event.key === 'Home' ? 0
            : event.key === 'End' ? last
            : null;
        if (next === null) {
            if (event.key === 'Escape') setActive(null);
            return;
        }
        event.preventDefault();
        setActive(next);
    };

    const first = series[0];
    const lastPoint = first?.points[first.points.length - 1];
    const tooltipLeft = activeX !== undefined ? x(activeX) : 0;

    return (
        <figure className={cx('m-0 flex min-w-0 flex-col gap-3', className)}>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                {series.length > 1 ? (
                    <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-fg-soft" aria-label="Series">
                        {series.map((line) => (
                            <li key={line.key} className="flex items-center gap-1.5">
                                <LineKey color={line.color ?? 1} dashed={line.dashed} />
                                {line.label}
                            </li>
                        ))}
                    </ul>
                ) : null}
                <button
                    type="button"
                    aria-pressed={showTable}
                    onClick={() => setShowTable((value) => !value)}
                    className="ml-auto inline-flex h-11 items-center gap-1.5 rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg"
                >
                    <Icon name={showTable ? 'trend' : 'table'} size={16} />
                    {showTable ? 'Ver gráfico' : 'Ver tabla'}
                </button>
            </div>

            {summary ? (
                <p id={summaryId} className="sr-only">
                    {summary}
                </p>
            ) : null}

            <div ref={ref} className={cx('relative w-full min-w-0 [contain:inline-size]', showTable && 'hidden')}>
                <svg
                    role="img"
                    aria-label={title}
                    aria-describedby={summary ? summaryId : undefined}
                    tabIndex={0}
                    width={width}
                    height={height + MARGIN.top + MARGIN.bottom}
                    onPointerMove={onPointerMove}
                    onPointerLeave={() => setActive(null)}
                    onFocus={() => setActive((value) => value ?? xs.length - 1)}
                    onBlur={() => setActive(null)}
                    onKeyDown={onKeyDown}
                    className="block touch-pan-y overflow-visible rounded-control focus-visible:outline-offset-4"
                >
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                x1={MARGIN.left}
                                x2={MARGIN.left + plotWidth}
                                y1={y(tick)}
                                y2={y(tick)}
                                strokeWidth={1}
                                className={tick === ticks[0] ? 'stroke-line-strong' : 'stroke-line'}
                            />
                            <text x={MARGIN.left - 10} y={y(tick)} dy="0.32em" textAnchor="end" className="tabular fill-fg-muted text-xs">
                                {formatY(tick)}
                            </text>
                        </g>
                    ))}
                    {xTicks.map((tick, index) => (
                        <text
                            key={tick.x}
                            x={x(tick.x)}
                            y={MARGIN.top + height + 20}
                            textAnchor={index === 0 && xTicks.length > 1 ? 'start' : index === xTicks.length - 1 && xTicks.length > 1 ? 'end' : 'middle'}
                            className="fill-fg-muted text-xs"
                        >
                            {tick.label}
                        </text>
                    ))}

                    {references.map((reference) => (
                        <g key={reference.label} aria-hidden="true">
                            <line
                                x1={MARGIN.left}
                                x2={MARGIN.left + plotWidth}
                                y1={y(reference.y)}
                                y2={y(reference.y)}
                                strokeWidth={1}
                                strokeDasharray="2 4"
                                className="stroke-fg-muted"
                            />
                            <text x={MARGIN.left + plotWidth} y={y(reference.y) - 6} textAnchor="end" className="tabular fill-fg-muted text-xs font-semibold">
                                {reference.label}
                            </text>
                        </g>
                    ))}

                    {series.map((line) => {
                        const color = line.color ?? 1;
                        const path = line.points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(point.x)} ${y(point.y)}`).join(' ');
                        const firstX = line.points[0]?.x ?? 0;
                        const lastX = line.points[line.points.length - 1]?.x ?? 0;
                        const base = y(ticks[0]!);
                        return (
                            <g key={line.key}>
                                {line.area ? (
                                    <path d={`${path} L${x(lastX)} ${base} L${x(firstX)} ${base} Z`} fillOpacity={0.1} className={FILL[color]} />
                                ) : null}
                                <path
                                    d={path}
                                    fill="none"
                                    strokeWidth={2}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeDasharray={line.dashed ? '6 5' : undefined}
                                    className={STROKE[color]}
                                />
                            </g>
                        );
                    })}

                    {endLabel && first && lastPoint && activeX === undefined ? (
                        <g>
                            <circle cx={x(lastPoint.x)} cy={y(lastPoint.y)} r={4.5} strokeWidth={2} className={cx('stroke-surface', FILL[first.color ?? 1])} />
                            <text x={x(lastPoint.x) + 9} y={y(lastPoint.y)} dy="0.32em" className="tabular fill-fg text-[13px] font-bold">
                                {formatY(lastPoint.y)}
                            </text>
                        </g>
                    ) : null}

                    {activeX !== undefined ? (
                        <g aria-hidden="true">
                            <line x1={x(activeX)} x2={x(activeX)} y1={MARGIN.top} y2={MARGIN.top + height} strokeWidth={1} className="stroke-fg-muted" />
                            {series.map((line) => {
                                const value = valueAt(line, activeX);
                                return value === undefined ? null : (
                                    <circle
                                        key={line.key}
                                        cx={x(activeX)}
                                        cy={y(value)}
                                        r={4.5}
                                        strokeWidth={2}
                                        className={cx('stroke-surface', FILL[line.color ?? 1])}
                                    />
                                );
                            })}
                        </g>
                    ) : null}
                </svg>

                {activeX !== undefined ? (
                    <div
                        role="status"
                        className="pointer-events-none absolute top-0 z-10 flex min-w-36 flex-col gap-1 rounded-control bg-fg px-3 py-2 text-on-fg shadow-card"
                        style={{
                            left: Math.min(Math.max(tooltipLeft + 12, 0), Math.max(0, width - 168)),
                        }}
                    >
                        <span className="text-xs opacity-80">{formatX(activeX)}</span>
                        {series.map((line) => {
                            const value = valueAt(line, activeX);
                            return value === undefined ? null : (
                                <span key={line.key} className="flex items-center gap-2 text-[13px] whitespace-nowrap">
                                    <LineKey color={line.color ?? 1} dashed={line.dashed} />
                                    <b className="tabular">{formatY(value)}</b>
                                    <span className="opacity-80">{line.label}</span>
                                </span>
                            );
                        })}
                    </div>
                ) : null}
            </div>

            {showTable ? (
                <div className="relative overflow-x-auto rounded-control border border-line">
                    <table className="w-full border-collapse text-sm">
                        <caption className="sr-only">{title}</caption>
                        <thead>
                            <tr className="bg-subtle text-left text-[13px] text-fg-soft">
                                <th scope="col" className="px-3 py-2 font-semibold">
                                    Fecha
                                </th>
                                {series.map((line) => (
                                    <th key={line.key} scope="col" className="px-3 py-2 text-right font-semibold">
                                        {line.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {xs.map((at) => (
                                <tr key={at} className="border-t border-line">
                                    <th scope="row" className="px-3 py-2 text-left font-semibold">
                                        {formatX(at)}
                                    </th>
                                    {series.map((line) => {
                                        const value = valueAt(line, at);
                                        return (
                                            <td key={line.key} className="tabular px-3 py-2 text-right">
                                                {value === undefined ? '—' : formatY(value)}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
        </figure>
    );
}
