// Copies the PaymentHood plugin files into a WHMCS install, preserving the
// directory structure WHMCS expects. Mirrors README "Step 1: File Installation".
//
//   node scripts/deploy-plugin.mjs            (uses WHMCS_ROOT from .env)
//   node scripts/deploy-plugin.mjs <path>     (override the WHMCS root)
//
// Only the plugin's own top-level folders are copied (includes/, modules/).

import { config as loadEnv } from 'dotenv';
import { cp, access, stat } from 'node:fs/promises';
import { constants } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
loadEnv({ path: resolve(__dirname, '..', '.env') });

const repoRoot = resolve(__dirname, '..', '..');
const whmcsRoot = process.argv[2] ?? process.env.WHMCS_ROOT;

if (!whmcsRoot) {
  console.error('✗ WHMCS_ROOT not set. Add it to e2e/.env or pass it as an argument.');
  process.exit(1);
}

// Plugin source folders that must be merged into the WHMCS root.
const PLUGIN_DIRS = ['includes', 'modules'];

async function exists(p) {
  try {
    await access(p, constants.F_OK);
    return true;
  } catch {
    return false;
  }
}

async function main() {
  // Sanity-check the target really is a WHMCS install.
  const initPhp = join(whmcsRoot, 'init.php');
  if (!(await exists(initPhp))) {
    console.error(`✗ ${whmcsRoot} does not look like a WHMCS root (init.php not found).`);
    process.exit(1);
  }

  for (const dir of PLUGIN_DIRS) {
    const src = join(repoRoot, dir);
    const dest = join(whmcsRoot, dir);
    if (!(await exists(src))) {
      console.error(`✗ Source folder missing: ${src}`);
      process.exit(1);
    }
    const info = await stat(src);
    if (!info.isDirectory()) continue;

    // Merge into the existing WHMCS folder without wiping other modules.
    await cp(src, dest, { recursive: true, force: true });
    console.log(`✓ Copied ${dir}/ → ${dest}`);
  }

  console.log('\n✓ Plugin files deployed.');
  console.log('  Next: activate the gateway under Admin > System Settings > Payment Gateways,');
  console.log('  or run `npm run test:setup` to do it automatically.');
}

main().catch((err) => {
  console.error('✗ Deploy failed:', err);
  process.exit(1);
});
