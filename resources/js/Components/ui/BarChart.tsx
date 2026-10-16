import { useState } from 'react';

/**
 * UI-2 — a small bar chart of values by day, drawn in SVG (no chart
 * package). The values come from the server as they are; the chart only
 * draws them. For screen readers the same figures are in a table.
 */
export type ChartPoint = { label: string; value: number; display: string };

export default function BarChart({ points, title, height = 180 }: { points: ChartPoint[]; title: string; height?: number }) {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...points.map((p) => p.value));
  const width = 600;
  const gap = points.length > 40 ? 1 : 3;
  const barWidth = Math.max(2, (width - gap * (points.length - 1)) / Math.max(1, points.length));
  const shown = hover === null ? null : points[hover];

  return (
    <figure className="relative">
      <div className="mb-2 flex min-h-[2.5rem] items-end justify-between gap-3 text-xs text-slate-500" aria-hidden="true">
        {shown ? (
          <span><span className="font-semibold text-slate-900">{shown.display}</span> · {shown.label}</span>
        ) : (
          <span>Point at a bar to see its day.</span>
        )}
        <span>Highest: {points.reduce((best, p) => (p.value > best.value ? p : best), points[0] ?? { value: 0, display: '—', label: '' }).display}</span>
      </div>
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="h-44 w-full" role="img" aria-label={title} onMouseLeave={() => setHover(null)}>
        {[0.25, 0.5, 0.75].map((f) => (
          <line key={f} x1="0" x2={width} y1={height * f} y2={height * f} stroke="#e2e8f0" strokeDasharray="4 4" vectorEffect="non-scaling-stroke" />
        ))}
        {points.map((p, i) => {
          const h = p.value <= 0 ? 0 : Math.max(2, (p.value / max) * (height - 4));
          const x = i * (barWidth + gap);

          return (
            <g key={p.label} onMouseEnter={() => setHover(i)}>
              <rect x={x} y={0} width={barWidth + gap} height={height} fill="transparent" />
              <rect x={x} y={height - h} width={barWidth} height={h} rx={Math.min(3, barWidth / 3)} className={hover === i ? 'fill-indigo-700' : 'fill-indigo-500'} />
            </g>
          );
        })}
        <line x1="0" x2={width} y1={height - 0.5} y2={height - 0.5} stroke="#cbd5e1" vectorEffect="non-scaling-stroke" />
      </svg>
      <div className="mt-1 flex justify-between text-[11px] text-slate-500" aria-hidden="true">
        <span>{points[0]?.label}</span>
        <span>{points[points.length - 1]?.label}</span>
      </div>
      <table className="sr-only">
        <caption>{title}</caption>
        <tbody>
          {points.map((p) => (
            <tr key={p.label}><th scope="row">{p.label}</th><td>{p.display}</td></tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
