{{-- Phase B47: the look of billing PDFs — sans-serif only (owner decision 14), no remote resources. --}}
<style>
    @page { margin: 32px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 4px 0; }
    .right { text-align: right; }
    .muted { color: #4b5563; }
    .pre { white-space: pre-line; }
    .issuer { font-size: 16px; font-weight: bold; }
    .title { font-size: 20px; font-weight: bold; }
    .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; margin-top: 6px; }
    .section { margin-top: 18px; }
    .head { margin-bottom: 18px; }
    .meta td { width: 33%; }
    .lines { margin-top: 18px; }
    .lines th { text-align: left; border-bottom: 1px solid #d1d5db; font-size: 10px; color: #4b5563; }
    .lines th.right { text-align: right; }
    .lines td { border-bottom: 1px solid #f3f4f6; }
    .totals { width: 50%; margin-left: 50%; margin-top: 12px; }
    .totals .grand td { font-weight: bold; border-top: 1px solid #d1d5db; }
    .badge { display: inline-block; margin-top: 4px; padding: 2px 6px; border-radius: 4px; background: #f3f4f6; font-size: 9px; }
    .badge-paid { background: #dcfce7; color: #166534; }
    .badge-open { background: #fef3c7; color: #92400e; }
    .badge-void { background: #fee2e2; color: #991b1b; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #6b7280; text-align: center; }
</style>
