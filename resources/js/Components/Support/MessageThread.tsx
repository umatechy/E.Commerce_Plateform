import type { TicketMessage } from '@/lib/support';

/**
 * A ticket's conversation, oldest first. Message text is rendered as
 * plain text (React escapes it; line breaks are kept by CSS) — support
 * messages are never treated as HTML. Internal notes only reach the
 * team's view; the server leaves them out of the requester's.
 */
export default function MessageThread({
  messages,
  perspective,
  tone,
}: {
  messages: TicketMessage[];
  perspective: 'requester' | 'agent';
  tone: 'storefront' | 'admin';
}) {
  const sf = tone === 'storefront';

  return (
    <ol className="space-y-4" aria-label="Conversation">
      {messages.map((message) => {
        const own = perspective === 'requester' ? message.author_type === 'requester' : message.author_type === 'agent';
        const box = message.internal
          ? 'border-amber-300 bg-amber-50'
          : own
            ? sf
              ? 'border-sf-border bg-sf-surface'
              : 'border-blue-200 bg-blue-50'
            : sf
              ? 'border-sf-border bg-sf-bg'
              : 'border-gray-200 bg-white';

        return (
          <li key={message.id} className={`flex ${own ? 'justify-end' : 'justify-start'}`}>
            <div className={`w-full max-w-[90%] border p-4 sm:max-w-[80%] ${sf ? 'rounded-sf' : 'rounded'} ${box}`}>
              <div className={`mb-2 flex flex-wrap items-center gap-2 text-xs ${sf ? 'text-sf-muted' : 'text-gray-500'}`}>
                <span className={`font-semibold ${sf ? 'text-sf-text' : 'text-gray-800'}`}>{message.author_name}</span>
                {message.author_type === 'agent' && perspective === 'requester' && <span>· Support team</span>}
                {message.author_type === 'requester' && perspective === 'agent' && <span>· Requester</span>}
                {message.internal && <span className="rounded bg-amber-200 px-1.5 py-0.5 font-medium text-amber-900">Internal note</span>}
                <time dateTime={message.created_at} className="ml-auto">
                  {new Date(message.created_at).toLocaleString()}
                </time>
              </div>
              <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
