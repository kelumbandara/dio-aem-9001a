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

/** A customer, supplier, item or warehouse as the pick-lists show it. */
export type Lookup = { code: string; name: string };

export type DocTotals = { net: string; vat: string; levy?: string; gross: string };

/** One line of a quotation, order, invoice or purchase order. Progress fields exist only where they apply. */
export type DocLine = {
  line: number;
  item: string | null;
  item_name: string | null;
  description?: string | null;
  uom: string | null;
  qty: string;
  unit_price: string | null;
  discount_pct?: string | null;
  net_amount: string | null;
  tax_code?: string | null;
  qty_delivered?: string;
  qty_invoiced?: string;
  qty_received?: string;
  qty_billed?: string;
};

/** The row of a list: quotations, sales orders, invoices (customer side) and purchase orders (supplier side). */
export type DocHeader = {
  id: string;
  document_no: string;
  customer?: string;
  customer_name?: string;
  supplier?: string;
  supplier_name?: string;
  status: string;
  quote_date?: string;
  valid_until?: string;
  order_date?: string;
  required_date?: string;
  expected_date?: string;
  invoice_date?: string;
  due_date?: string;
  net_total?: string;
  vat_total?: string;
  gross_total?: string;
  warehouse?: string | null;
};

export type DocLink = { id: string; document_no: string; date?: string; status?: string; gross_total?: string };

export type DocDetail = DocHeader & {
  lines: DocLine[];
  totals: DocTotals;
  notes?: string | null;
  reference?: string | null;
  customer_ref?: string | null;
  supplier_ref?: string | null;
  terms?: string | null;
  version?: number;
  cancel_reason?: string | null;
  approval_request_id?: string | null;
  quotation?: string | null;
  order?: string | null;
  order_id?: string | null;
  deliveries?: DocLink[];
  invoices?: DocLink[];
  credit_notes?: DocLink[];
  receipts?: DocLink[];
  bills?: DocLink[];
};

export type NewLine = { item: string; qty: string; unit_price?: string; discount_pct?: string };
