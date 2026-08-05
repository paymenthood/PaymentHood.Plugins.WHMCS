// Optional direct-DB verification of WHMCS state. Only used when DB_* is set in
// .env. Gives a stronger assertion than the UI alone (e.g. order status), since
// the storefront does not surface raw order status to the customer.
import mysql from 'mysql2/promise';
import { cfg, GATEWAY } from '../fixtures/config.js';

async function withConnection<T>(fn: (conn: mysql.Connection) => Promise<T>): Promise<T> {
  const conn = await mysql.createConnection({
    host: cfg.db.host,
    port: cfg.db.port,
    user: cfg.db.user,
    password: cfg.db.pass,
    database: cfg.db.name,
  });
  try {
    return await fn(conn);
  } finally {
    await conn.end();
  }
}

export async function getInvoiceStatus(invoiceId: number): Promise<string | null> {
  return withConnection(async (conn) => {
    const [rows] = await conn.query('SELECT status FROM tblinvoices WHERE id = ? LIMIT 1', [invoiceId]);
    const r = rows as Array<{ status: string }>;
    return r.length ? r[0].status : null;
  });
}

export async function getOrderStatusByInvoice(invoiceId: number): Promise<string | null> {
  return withConnection(async (conn) => {
    const [rows] = await conn.query('SELECT status FROM tblorders WHERE invoiceid = ? ORDER BY id DESC LIMIT 1', [
      invoiceId,
    ]);
    const r = rows as Array<{ status: string }>;
    return r.length ? r[0].status : null;
  });
}

export async function getGatewaySetting(setting: string): Promise<string | null> {
  return withConnection(async (conn) => {
    const [rows] = await conn.query(
      'SELECT value FROM tblpaymentgateways WHERE gateway = ? AND setting = ? LIMIT 1',
      [GATEWAY, setting],
    );
    const r = rows as Array<{ value: string }>;
    return r.length ? r[0].value : null;
  });
}

/** True when the PaymentHood gateway has completed OAuth activation. */
export async function isGatewayActivated(): Promise<boolean> {
  return (await getGatewaySetting('activated')) === '1';
}
