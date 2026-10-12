/** The colour a status badge gets. */
export function statusTone(status: string): 'ok' | 'warn' | 'danger' | 'muted' {
  if (status === 'approved' || status === 'executed' || status === 'completed') return 'ok';
  if (status === 'pending' || status === 'in_progress') return 'warn';
  if (status === 'rejected' || status === 'execution_failed') return 'danger';

  return 'muted';
}
