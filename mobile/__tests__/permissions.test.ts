import { featuresFor, isOlderVersion } from '@/util/permissions';

describe('featuresFor', () => {
  const all = ['manufacturing.output.report', 'manufacturing.downtime.report', 'manufacturing.qc.inspect', 'inventory.stock.view', 'ai.use'];
  const modules = ['core', 'approvals', 'manufacturing', 'inventory', 'ai'];

  it('shows what the person may do', () => {
    expect(featuresFor(all, modules)).toEqual({ approvals: true, production: true, downtime: true, quality: true, stock: true, assistant: true, sales: false, quotations: false, purchasing: false, purchaseOrders: false });
  });

  it('shows nothing a person has no permission for', () => {
    expect(featuresFor(['inventory.stock.view'], modules)).toEqual({ approvals: true, production: false, downtime: false, quality: false, stock: true, assistant: false, sales: false, quotations: false, purchasing: false, purchaseOrders: false });
    expect(featuresFor([], modules).stock).toBe(false);
  });

  it('shows nothing of a module that is switched off', () => {
    expect(featuresFor(all, ['core'])).toEqual({ approvals: false, production: false, downtime: false, quality: false, stock: false, assistant: false, sales: false, quotations: false, purchasing: false, purchaseOrders: false });
    expect(featuresFor(all, ['core', 'manufacturing']).stock).toBe(false);
  });
});

describe('isOlderVersion', () => {
  it('compares versions number by number', () => {
    expect(isOlderVersion('1.0.0', '1.0.1')).toBe(true);
    expect(isOlderVersion('1.9.0', '1.10.0')).toBe(true);
    expect(isOlderVersion('2.0.0', '1.99.99')).toBe(false);
    expect(isOlderVersion('1.2.0', '1.2.0')).toBe(false);
    expect(isOlderVersion('1.2', '1.2.0')).toBe(false);
    expect(isOlderVersion('1.2', '1.2.1')).toBe(true);
  });
});

describe('featuresFor sales and purchasing', () => {
  const modules = ['core', 'sales', 'procurement'];

  it('lets a person look at documents without letting them make any', () => {
    const f = featuresFor(['sales.view', 'procurement.view'], modules);
    expect(f).toMatchObject({ sales: true, quotations: false, purchasing: true, purchaseOrders: false });
  });

  it('lets a person make quotations or purchase orders only with the matching permission', () => {
    expect(featuresFor(['sales.view', 'sales.quotation.manage'], modules)).toMatchObject({ sales: true, quotations: true, purchaseOrders: false });
    expect(featuresFor(['procurement.view', 'procurement.order.manage'], modules)).toMatchObject({ purchasing: true, purchaseOrders: true, quotations: false });
  });

  it('never offers making a document without being able to see them', () => {
    expect(featuresFor(['sales.quotation.manage', 'procurement.order.manage'], modules)).toMatchObject({ sales: false, quotations: false, purchasing: false, purchaseOrders: false });
  });

  it('shows nothing of a module that is switched off', () => {
    const all = ['sales.view', 'sales.quotation.manage', 'procurement.view', 'procurement.order.manage'];
    expect(featuresFor(all, ['core'])).toMatchObject({ sales: false, quotations: false, purchasing: false, purchaseOrders: false });
    expect(featuresFor(all, ['core', 'sales'])).toMatchObject({ sales: true, quotations: true, purchasing: false, purchaseOrders: false });
  });
});
