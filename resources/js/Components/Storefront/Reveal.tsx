import { useEffect, useRef, type ElementType, type PropsWithChildren } from 'react';
import { motionOf, motionTokens, STAGGER_ITEMS, useStorefrontTheme } from '@/Storefront/theme';

/**
 * Module 18 §22–23 (Phase B36): shows its content with the theme's entry
 * motion when it scrolls into view.
 *
 * - Off unless the store's motion has reveal on (and the package allows it —
 *   the server already applied that), and never with prefers-reduced-motion.
 * - The content is rendered visible; only this script hides it, just before
 *   watching it, so without scripts (or if the observer is missing) nothing
 *   is hidden (§22 rule 1).
 * - `index` staggers items of a grid: at most STAGGER_ITEMS steps, so a big
 *   catalogue never waits on hundreds of animations.
 */
export default function Reveal({ as: Tag = 'div', index = 0, className, children }: PropsWithChildren<{ as?: ElementType; index?: number; className?: string }>) {
  const ref = useRef<HTMLElement | null>(null);
  const theme = useStorefrontTheme();
  const enabled = theme?.reveal === true;
  const step = theme ? motionTokens(motionOf(theme.shell)).stagger : 0;

  useEffect(() => {
    const element = ref.current;
    if (!enabled || !element || typeof IntersectionObserver === 'undefined') return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

    element.style.setProperty('--sf-delay', `${Math.min(index, STAGGER_ITEMS - 1) * step}ms`);
    element.classList.add('sf-reveal-pending');
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('sf-reveal-visible');
            entry.target.classList.remove('sf-reveal-pending');
            observer.unobserve(entry.target);
          }
        }
      },
      { rootMargin: '0px 0px -8% 0px', threshold: 0.05 },
    );
    observer.observe(element);

    return () => {
      observer.disconnect();
      // Leaving the page or a change of theme: never leave content hidden (§34).
      element.classList.remove('sf-reveal-pending');
    };
  }, [enabled, index, step]);

  return (
    <Tag ref={ref} className={className}>
      {children}
    </Tag>
  );
}
