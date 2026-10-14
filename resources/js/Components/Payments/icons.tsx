/**
 * Phase B48: the few line icons the payment screens use (admin and
 * storefront). Decorative only — every icon sits next to text that says the
 * same — so they are hidden from screen readers. They take the text colour.
 */
type IconProps = { className?: string };

function Svg({ className = 'h-5 w-5', children }: IconProps & { children: React.ReactNode }) {
  return (
    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" className={className}>
      {children}
    </svg>
  );
}

export const PhoneIcon = (p: IconProps) => (
  <Svg {...p}>
    <rect x="7" y="2.5" width="10" height="19" rx="2" />
    <path d="M11 18.5h2" />
  </Svg>
);
export const ClockIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 7v5l3 2" />
  </Svg>
);
export const CheckIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="m8 12.5 2.5 2.5L16 9.5" />
  </Svg>
);
export const CrossIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="m9 9 6 6M15 9l-6 6" />
  </Svg>
);
export const AlertIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12 3.5 2.5 20h19L12 3.5Z" />
    <path d="M12 10v4.5M12 17.5h.01" />
  </Svg>
);
export const CashIcon = (p: IconProps) => (
  <Svg {...p}>
    <rect x="2.5" y="6" width="19" height="12" rx="2" />
    <circle cx="12" cy="12" r="2.5" />
    <path d="M6 9.5v5M18 9.5v5" />
  </Svg>
);
export const BankIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M3 9.5 12 4l9 5.5" />
    <path d="M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 20h18" />
  </Svg>
);
export const WalletIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 7.5h14a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5.5A1.5 1.5 0 0 1 4 18.5V7.5Z" />
    <path d="M4 7.5 15.5 4v3.5M16 13.5h.01" />
  </Svg>
);
export const CardIcon = (p: IconProps) => (
  <Svg {...p}>
    <rect x="2.5" y="5.5" width="19" height="13" rx="2" />
    <path d="M2.5 10h19M6 15h3" />
  </Svg>
);
export const CopyIcon = (p: IconProps) => (
  <Svg {...p}>
    <rect x="8.5" y="8.5" width="11" height="11" rx="2" />
    <path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5" />
  </Svg>
);
