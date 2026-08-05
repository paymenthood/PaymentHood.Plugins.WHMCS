// Central, typed access to the .env configuration. Import `cfg` anywhere.
import { config as loadEnv } from 'dotenv';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
loadEnv({ path: resolve(__dirname, '..', '.env') });

function req(name: string): string {
  const v = process.env[name];
  if (!v || v.trim() === '') {
    throw new Error(`Missing required env var "${name}". Copy e2e/.env.example to e2e/.env and fill it in.`);
  }
  return v.trim();
}

function opt(name: string, fallback = ''): string {
  return (process.env[name] ?? fallback).trim();
}

function bool(name: string, fallback = false): boolean {
  const v = process.env[name];
  if (v == null || v.trim() === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(v.trim().toLowerCase());
}

const baseURL = req('WHMCS_BASE_URL').replace(/\/+$/, '');
const adminPath = opt('WHMCS_ADMIN_PATH', 'admin').replace(/^\/+|\/+$/g, '');

export const cfg = {
  whmcs: {
    baseURL,
    adminURL: `${baseURL}/${adminPath}`,
    adminUser: req('WHMCS_ADMIN_USER'),
    adminPass: req('WHMCS_ADMIN_PASS'),
  },
  client: {
    register: bool('CLIENT_REGISTER', false),
    email: req('CLIENT_EMAIL'),
    password: req('CLIENT_PASSWORD'),
    firstName: opt('CLIENT_FIRST_NAME', 'Test'),
    lastName: opt('CLIENT_LAST_NAME', 'Customer'),
  },
  product: {
    pid: req('PRODUCT_PID'),
    billingCycle: opt('PRODUCT_BILLING_CYCLE', 'monthly'),
  },
  paymenthood: {
    merchantEmail: opt('PH_MERCHANT_EMAIL'),
    merchantPass: opt('PH_MERCHANT_PASSWORD'),
    card: {
      number: opt('PH_CARD_NUMBER', '4111111111111111'),
      exp: opt('PH_CARD_EXP', '12/30'),
      cvc: opt('PH_CARD_CVC', '123'),
      name: opt('PH_CARD_NAME', 'Test Customer'),
    },
    providerName: opt('PH_PROVIDER_NAME'),
  },
  db: {
    enabled: !!process.env.DB_NAME,
    host: opt('DB_HOST', '127.0.0.1'),
    port: Number(opt('DB_PORT', '3306')),
    name: opt('DB_NAME', 'whmcs'),
    user: opt('DB_USER', 'root'),
    pass: opt('DB_PASS', ''),
  },
} as const;

export const GATEWAY = 'paymenthood';
