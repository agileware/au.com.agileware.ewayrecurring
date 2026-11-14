import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4 } from '../../fixtures/civi';
import { browserApi4, uniqueName } from '../../fixtures/backend';
import { liveProcessorId } from '../../fixtures/recurring-records';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-permissions: who may read and change saved cards (the PaymentToken entity). The extension adds two permissions,
 * "view payment tokens" (reading) and "edit payment tokens" (everything else), and lets either one, or CiviContribute
 * access, through where core would refuse. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * The calls are made from a signed-in CiviCRM page with CRM.api4(), the call the APIv4 Explorer and every CiviCRM screen
 * use, so they run through the ajax route as that user with their permissions checked. The Explorer's own screen is not
 * driven: for anyone but an administrator its list of actions comes back empty, so it cannot be used to run a call.
 */

/** The browser reports each refused call as a console error and a failed request. */
const REFUSED = /status of 403|HTTP 403 from .*ajax/;

/** The seeded donor's id. */
function donorId(): number {
  return api4<{ id: number }>('Contact', 'get', {
    select: ['id'],
    where: [['first_name', '=', testData.contacts.donor.firstName], ['last_name', '=', testData.contacts.donor.lastName]],
  })[0].id;
}

/** The seeded donor's saved card. */
function seededToken(): Record<string, any> {
  return api4('PaymentToken', 'get', {
    select: ['id', 'token', 'masked_account_number', 'contact_id'],
    where: [['token', '=', testData.tokens.valid.token]],
  })[0];
}

function tokenByValue(token: string): Record<string, any> | undefined {
  return api4('PaymentToken', 'get', { select: ['id', 'token', 'masked_account_number'], where: [['token', '=', token]] })[0];
}

/** A page that has CRM.api4 on it: any CiviCRM screen the user may open. */
async function openCivi(page: Page): Promise<void> {
  await page.goto(adminUrl('dashboard', { reset: 1 }));
  await page.waitForFunction(() => typeof (window as any).CRM?.api4 === 'function');
}

test.describe('payment token permissions', () => {
  test.describe('a user who may only view', () => {
    test.use({ allowedProblems: [REFUSED] });

    test('PW-B-permissions-01 with "view payment tokens" the tokens can be read, and creating, changing and deleting them is refused', async ({ tokenviewerPage }) => {
      const seeded = seededToken();
      const attempted = uniqueName('pw-viewer').replace(/ /g, '-');
      await openCivi(tokenviewerPage);

      // Reading is allowed, and shows the seeded card.
      const read = await browserApi4(tokenviewerPage, 'PaymentToken', 'get', { select: ['id', 'token', 'masked_account_number'], where: [['id', '=', seeded.id]] });
      expect(read).toMatchObject({ ok: true });
      expect(read.values).toEqual([{ id: seeded.id, token: seeded.token, masked_account_number: seeded.masked_account_number }]);

      // Everything that writes is refused with a 403.
      const created = await browserApi4(tokenviewerPage, 'PaymentToken', 'create', {
        values: { contact_id: donorId(), payment_processor_id: liveProcessorId(), token: attempted },
      });
      expect(created).toEqual({ ok: false, status: 403 });
      const updated = await browserApi4(tokenviewerPage, 'PaymentToken', 'update', {
        values: { masked_account_number: 'CHANGED' },
        where: [['id', '=', seeded.id]],
      });
      expect(updated).toEqual({ ok: false, status: 403 });
      const deleted = await browserApi4(tokenviewerPage, 'PaymentToken', 'delete', { where: [['id', '=', seeded.id]] });
      expect(deleted).toEqual({ ok: false, status: 403 });

      // And nothing changed.
      expect(tokenByValue(attempted)).toBeUndefined();
      expect(seededToken()).toEqual(seeded);
    });
  });

  test.describe('a user with CiviContribute access', () => {
    test('PW-B-permissions-02 the finance user can read, create, change and delete tokens', async ({ financePage }) => {
      const token = uniqueName('pw-finance').replace(/ /g, '-');
      await openCivi(financePage);
      try {
        const read = await browserApi4(financePage, 'PaymentToken', 'get', { select: ['id', 'token'], where: [['token', '=', testData.tokens.valid.token]] });
        expect(read).toMatchObject({ ok: true });
        expect(read.values).toHaveLength(1);

        const created = await browserApi4(financePage, 'PaymentToken', 'create', {
          values: { contact_id: donorId(), payment_processor_id: liveProcessorId(), token, masked_account_number: '444433XXXXXX1111' },
        });
        expect(created.ok).toBe(true);
        expect(created.values).toHaveLength(1);
        expect(tokenByValue(token)).toMatchObject({ masked_account_number: '444433XXXXXX1111' });

        const updated = await browserApi4(financePage, 'PaymentToken', 'update', {
          values: { masked_account_number: '510510XXXXXX5100' },
          where: [['token', '=', token]],
        });
        expect(updated.ok).toBe(true);
        expect(tokenByValue(token)).toMatchObject({ masked_account_number: '510510XXXXXX5100' });

        const deleted = await browserApi4(financePage, 'PaymentToken', 'delete', { where: [['token', '=', token]] });
        expect(deleted.ok).toBe(true);
        expect(tokenByValue(token)).toBeUndefined();
      } finally {
        api4('PaymentToken', 'delete', { where: [['token', '=', token]] });
      }
    });
  });

  test.describe('a user with no token permission', () => {
    test.use({ allowedProblems: [REFUSED] });

    test('PW-B-permissions-03 the limited user is refused every call, reading included', async ({ limitedPage }) => {
      const seeded = seededToken();
      const attempted = uniqueName('pw-limited').replace(/ /g, '-');
      await openCivi(limitedPage);

      const calls: [string, Record<string, unknown>][] = [
        ['get', { select: ['id', 'token'], where: [['id', '=', seeded.id]] }],
        ['create', { values: { contact_id: donorId(), payment_processor_id: liveProcessorId(), token: attempted } }],
        ['update', { values: { masked_account_number: 'CHANGED' }, where: [['id', '=', seeded.id]] }],
        ['delete', { where: [['id', '=', seeded.id]] }],
      ];
      for (const [action, params] of calls) {
        expect(await browserApi4(limitedPage, 'PaymentToken', action, params), action).toEqual({ ok: false, status: 403 });
      }

      expect(tokenByValue(attempted)).toBeUndefined();
      expect(seededToken()).toEqual(seeded);
    });
  });
});
