import { useT } from '@/Storefront/i18n';

/** Reusable loading-state block. Pages that use it load through useApi, which ends a silent wait in an error with "Try again". */
export default function LoadingState() {
  const t = useT(); // English outside a storefront page

  return (
    <div role="status" className="p-8 text-center text-slate-500">
      {t('Loading…')}
    </div>
  );
}
