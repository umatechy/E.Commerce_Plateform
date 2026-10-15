import AdminPage from '@/Components/AdminPage';
import { EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { ButtonLink } from '@/Components/ui/Button';
import ArticleView from '@/Components/Help/ArticleView';
import { useApi } from '@/lib/useApi';
import type { HelpArticle } from '@/lib/help';

/** Phase B54: one help article in the admin. An article the reader may not open is "not found". */
export default function HelpArticlePage({ articleKey }: { articleKey: string }) {
  const state = useApi<{ data: HelpArticle }>(`/help/articles/${articleKey}`);
  const trail = [{ label: 'Help center', href: '/help' }, { label: state.data?.data.title ?? 'Article' }];

  return (
    <AdminPage title="Help center" trail={trail}>
      <div className="mx-auto max-w-3xl rounded-lg border border-slate-200 bg-white p-5 sm:p-8">
        {state.errorStatus === 404 ? (
          <EmptyPanel title="Article not found" description="It may have been moved or archived. Search the help center instead." action={<ButtonLink href="/help">Help center</ButtonLink>} />
        ) : state.error ? (
          <ErrorPanel message={state.error} onRetry={state.reload} />
        ) : state.data === null ? (
          <Skeleton lines={8} />
        ) : (
          <ArticleView key={state.data.data.key} article={state.data.data} />
        )}
      </div>
    </AdminPage>
  );
}
