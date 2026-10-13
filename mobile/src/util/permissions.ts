/** What the signed-in person can do on the phone. The server decides everything again on every request; this only hides what would be refused. */

export type Features = {
  approvals: boolean;
  production: boolean;
  downtime: boolean;
  quality: boolean;
  stock: boolean;
  assistant: boolean;
  /** Look at quotations, orders and invoices. */
  sales: boolean;
  /** Make a quotation and send it, accept it or drop it. */
  quotations: boolean;
  /** Look at purchase orders. */
  purchasing: boolean;
  /** Make a purchase order and send it for approval. */
  purchaseOrders: boolean;
};

export function featuresFor(permissions: string[], modules: string[]): Features {
  const has = (p: string) => permissions.includes(p);
  const on = (m: string) => modules.includes(m);

  return {
    approvals: on('approvals'),
    production: on('manufacturing') && has('manufacturing.output.report'),
    downtime: on('manufacturing') && has('manufacturing.downtime.report'),
    quality: on('manufacturing') && has('manufacturing.qc.inspect'),
    stock: on('inventory') && has('inventory.stock.view'),
    assistant: on('ai') && has('ai.use'),
    sales: on('sales') && has('sales.view'),
    quotations: on('sales') && has('sales.view') && has('sales.quotation.manage'),
    purchasing: on('procurement') && has('procurement.view'),
    purchaseOrders: on('procurement') && has('procurement.view') && has('procurement.order.manage'),
  };
}

/** True when a version like "1.2.0" is older than the minimum the server accepts. */
export function isOlderVersion(current: string, minimum: string): boolean {
  const a = current.split('.').map((n) => parseInt(n, 10) || 0);
  const b = minimum.split('.').map((n) => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const x = a[i] ?? 0;
    const y = b[i] ?? 0;
    if (x !== y) return x < y;
  }

  return false;
}
