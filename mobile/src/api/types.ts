/** Shapes of the server's answers (docs/architecture/api-standards.md). Decimals are strings, never numbers. */

export type User = {
  id: string;
  name: string;
  email: string;
  user_type: string;
  locale: string | null;
  mfa_enabled: boolean;
  mfa_enrollment_required: boolean;
  roles: string[];
  permissions: string[];
};

export type LoginResult = { token: string; token_type: 'Bearer'; expires_at: string | null; user: User };

export type MobileConfig = {
  company: string;
  logo_url: string | null;
  modules: string[];
  min_app_version: string;
  locales: string[];
  server_time: string;
};

export type Page<T> = { data: T[]; meta?: { current_page: number; per_page: number; total: number } };

export type Approval = {
  id: string;
  document_no: string;
  action_key: string;
  summary: string;
  status: string;
  amount: string | null;
  requested_by_name: string | null;
  expires_at: string;
  created_at: string | null;
  steps?: { order: number; label: string | null; status: string; decided_by: { name: string | null } | null }[];
  decisions?: { decision: string; decided_by: { name: string | null }; comment: string | null; decided_at: string }[];
};

export type OrderHeader = {
  id: string;
  order_no: string;
  item: string;
  item_name: string;
  uom: string;
  status: string;
  planned_qty: string;
  planned_start: string;
  planned_end: string;
};

export type Order = OrderHeader & {
  reported_qty: string;
  accepted_qty: string;
  rejected_qty: string;
  rework_qty: string;
  received_qty: string;
  pending_qty: string;
  line: string | null;
};

export type SetupRow = { id: string; code: string; name: string; is_active: boolean; category?: string; start_time?: string; end_time?: string };

export type StockRow = { item_code: string; item_name: string; uom: string; on_hand: string; reserved: string; available: string };

export type StockBalance = { warehouse: string; warehouse_name: string; location: string | null; lot: string | null; expiry_date: string | null; on_hand: string };

export type StockItem = { item_code: string; item_name: string; uom: string; balances: StockBalance[] };

export type AppNotification = { id: string; title: string; body: string; url: string | null; level: string; read: boolean; created_at: string };

export type Agent = { key: string; name: string; description: string };

export type ChatMessage = { id: number; role: 'user' | 'assistant'; content: string; refs: { label: string; url: string }[]; created_at: string };

export type Conversation = { id: string; title: string; agent: string; updated_at: string; messages?: ChatMessage[] };
