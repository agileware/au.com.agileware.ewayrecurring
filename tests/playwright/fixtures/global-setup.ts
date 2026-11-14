import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import users from './test-users.json';
import { CMS, login } from './cms';

/**
 * Log each persona in once and save the session, so specs start already signed in.
 *
 * The personas are logged in one after another, not in parallel: WordPress keeps a user's sessions in a single
 * meta value, and parallel logins as the same user can invalidate each other.
 *
 * PERSONAS limits which are logged in (a comma separated list), for sites where the others have not been set up.
 */
export const GENERATED = join(__dirname, '.generated');

export function statePath(persona: string): string {
  return join(GENERATED, `${persona}.json`);
}

export function credentials(persona: string): { username: string; password: string } {
  if (persona === 'admin') {
    return { username: process.env.WP_ADMIN_USER || 'admin', password: process.env.WP_ADMIN_PASS || 'admin' };
  }
  const user = (users as unknown as Record<string, { username: string; password: string }>)[persona];
  if (!user) {
    throw new Error(`Unknown persona "${persona}"`);
  }
  return { username: user.username, password: user.password };
}

export const ALL_PERSONAS = ['admin', 'finance', 'tokenviewer', 'limited'];

export default async function globalSetup(config: FullConfig): Promise<void> {
  mkdirSync(GENERATED, { recursive: true });
  const wanted = (process.env.PERSONAS || ALL_PERSONAS.join(',')).split(',').map((p) => p.trim()).filter(Boolean);
  const baseURL = config.projects[0].use.baseURL as string;

  const browser = await chromium.launch();
  try {
    for (const persona of wanted) {
      const { username, password } = credentials(persona);
      const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
      const page = await context.newPage();
      try {
        await login(page, username, password);
      } catch (error) {
        throw new Error(`Could not sign in the "${persona}" persona on ${CMS}: ${(error as Error).message}`);
      }
      await context.storageState({ path: statePath(persona) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
