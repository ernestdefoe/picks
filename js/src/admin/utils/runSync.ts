import app from 'flarum/admin/app';

type SyncState = { status: string; result: Record<string, any> | null };

/** Why a sync did not finish: the server's own message, or a run still going after the wait. */
export class SyncFailed extends Error {
  constructor(
    public serverMessage: string | null = null,
    public timedOut = false
  ) {
    super(serverMessage || 'sync failed');
  }
}

/** Polls every 2 seconds for up to 5 minutes. */
const POLL_MS = 2000;
const MAX_POLLS = 150;

const apiUrl = () => app.forum.attribute<string>('apiUrl') + '/picks';

/**
 * Start an admin sync and resolve with its result once it has run.
 *
 * The server queues every sync and answers 202 at once; `task` names the
 * status to poll. An answer that is not "queued" or "running" (a team with a
 * custom crest, say) is returned as it is, without polling.
 */
export default function runSync<T = Record<string, any>>(startPath: string, task: string, body?: Record<string, unknown>): Promise<T> {
  return app.request<Record<string, any>>({ method: 'POST', url: apiUrl() + startPath, body }).then((started) => {
    if (started && started.status !== 'queued' && started.status !== 'running') return started as T;

    return poll<T>(task, 0);
  });
}

function poll<T>(task: string, attempt: number): Promise<T> {
  return app.request<SyncState>({ method: 'GET', url: `${apiUrl()}/sync/${task}/status` }).then((state) => {
    if (state.status === 'done') return (state.result || {}) as T;
    if (state.status === 'failed') throw new SyncFailed(state.result?.message ?? null);
    if (attempt >= MAX_POLLS) throw new SyncFailed(null, true);

    return new Promise<T>((resolve, reject) => {
      setTimeout(() => poll<T>(task, attempt + 1).then(resolve, reject), POLL_MS);
    });
  });
}
