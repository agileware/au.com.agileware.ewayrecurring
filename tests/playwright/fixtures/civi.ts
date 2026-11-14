import { execFileSync } from 'node:child_process';
import { CIVI_CV } from './cms';

/**
 * Run cv against the site under test, so specs can read and change CiviCRM data directly.
 *
 * CIVI_EXEC_PREFIX is whatever runs a command where CiviCRM is, for example
 *   docker exec -u www-data -w <extension folder> wordpress
 *   ddev exec -d <extension folder>
 * It is split on spaces, so it cannot contain quoted arguments. Leave it empty when cv runs on this machine.
 * CIVI_EXEC_CWD is the folder to run the prefix from (ddev needs the project folder).
 */
const PREFIX = (process.env.CIVI_EXEC_PREFIX || '').trim().split(/\s+/).filter(Boolean);
const CWD = process.env.CIVI_EXEC_CWD || undefined;

function run(args: string[]): string {
  const [command, ...rest] = [...PREFIX, CIVI_CV, ...args];
  try {
    return execFileSync(command, rest, { cwd: CWD, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 }).toString();
  } catch (error: any) {
    const stderr = error?.stderr?.toString?.() ?? '';
    throw new Error(`cv ${args.join(' ').slice(0, 120)} failed: ${stderr || error.message}`);
  }
}

/** Run an APIv4 call and return its values. Permission checks are off, as with cv on the command line. */
export function api4<T = any>(entity: string, action: string, params: Record<string, unknown> = {}): T[] {
  // The parameters go as an argument, not on stdin: `docker exec` without -i does not pass stdin on.
  const out = run(['api4', `${entity}.${action}`, JSON.stringify(params), '--out=json']);
  return JSON.parse(out);
}

/** Run PHP inside CiviCRM and return what it echoed. */
export function evaluate(php: string): string {
  return run(['ev', php]).trim();
}

/** Run PHP inside CiviCRM and parse what it echoed as JSON. */
export function evaluateJson<T = any>(php: string): T {
  return JSON.parse(evaluate(php));
}

/** Run a PHP file inside CiviCRM (path as seen where cv runs). */
export function script(path: string): string {
  return run(['scr', path]).trim();
}

/** Read a CiviCRM setting. */
export function getSetting<T = any>(name: string): T {
  const rows = api4('Setting', 'get', { select: [name] });
  return rows[0]?.value as T;
}

/** Set CiviCRM settings. */
export function setSettings(values: Record<string, unknown>): void {
  api4('Setting', 'set', { values });
}
