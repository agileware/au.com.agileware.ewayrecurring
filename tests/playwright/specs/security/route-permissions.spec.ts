import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl, CMS } from '../../fixtures/cms';
import { api4, evaluateJson } from '../../fixtures/civi';
import { storedSettings } from '../../fixtures/backend';
import { responses } from '../../fixtures/eway-stub';
import { newAccessCode } from '../../fixtures/contribution-page';
import { createPendingPayment } from '../../fixtures/queue-records';
import {
  PHP_OUTPUT,
  XHR,
  billingForm,
  get,
  isJsonText,
  newContact,
  post,
  routeUrl,
  seededProcessors,
  tokenTotal,
  visibleText,
  withoutCoreNoise,
  type Answer,
} from '../../fixtures/security';

/**
 * PW-S-03: who may open each of the extension's routes and API entities. The result is checked for a visitor who is not
 * signed in and for each persona (admin, finance, tokenviewer, limited), with plain HTTP requests so the status and the
 * body are seen as they are. Test plan: developer/TEST_PLAN.md section 5.3.
 *
 * The routes and the permission each one asks for (xml/Menu/eWAYRecurring.xml):
 *
 *   civicrm/ewayrecurring/verifypayment   make online contributions   (eWAY's redirect arrives with no login)
 *   civicrm/ewayrecurring/savetoken       make online contributions   (the same)
 *   civicrm/ewayrecurring/createtoken     access CiviContribute       (staff only, and an AJAX route)
 *   civicrm/ewayrecurring/settings        administer CiviCRM
 *
 * and the API entities the extension adds (APIv3: EwayContributionTransactions, EwayRecurring, EwaySettlement), which
 * declare no permission of their own and so fall back to core's default for an entity it does not know, "administer
 * CiviCRM". PW-B-permissions covers the PaymentToken entity (the extension's own permissions) call by call for every
 * persona, PW-F6-04 and PW-F6-07 the token routes' own behaviour, so this case is the whole map in one place plus the
 * attacks on it.
 *
 * On WordPress a signed-in user does not inherit the anonymous role's permissions, so a persona without "make online
 * contributions" is refused the two public routes that a visitor may use (found by PW-F6-07). The other CMSs differ, so
 * the signed-in results for those two routes are only held to "no PHP output and no data" there.
 */

type Persona = 'anon' | 'admin' | 'finance' | 'tokenviewer' | 'limited';
const PERSONAS: Persona[] = ['anon', 'admin', 'finance', 'tokenviewer', 'limited'];

/** The refusals are HTTP 403 here and a 500 page on a fresh site, and a signed-out visit to the admin pages goes to the login. */
const REFUSED = /status of (403|500)|HTTP (403|500) from/;
test.use({ allowedProblems: [REFUSED] });

/** True when the answer is a refusal: an error status, or (for an admin page seen without a session) the login page. */
function isRefusal(result: Answer): boolean {
  return result.status >= 400 || (result.status >= 300 && /wp-login\.php|\/user\/login|\/civicrm\/login|option=com_users/.test(result.location ?? ''));
}

/** Pages of each persona. */
function pagesOf(fixtures: Record<Persona, Page>): [Persona, Page][] {
  return PERSONAS.map((persona) => [persona, fixtures[persona]]);
}

test.describe('the routes of the extension, persona by persona', () => {
  test('PW-S-03 each route gives each persona the documented result, and a refusal shows nothing', async ({ anonPage, adminPage, financePage, tokenviewerPage, limitedPage, stub }) => {
    const pages = pagesOf({ anon: anonPage, admin: adminPage, finance: financePage, tokenviewer: tokenviewerPage, limited: limitedPage });
    const { live } = seededProcessors();
    const contact = newContact('s03route');
    // Whoever may create the hosted page gets one.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('S03')), { repeat: true });

    const wordpress = CMS === 'wordpress';
    /** Who may use each route, on WordPress. The public ones are for the visitor and, being administrators, the admin. */
    const allowed: Record<string, Persona[]> = {
      verifypayment: ['anon', 'admin'],
      savetoken: ['anon', 'admin'],
      createtoken: ['admin', 'finance'],
      settings: ['admin'],
    };

    for (const [persona, page] of pages) {
      const who = (route: string) => `${route} as ${persona}`;
      const mayUse = (route: string) => allowed[route].includes(persona);

      // verifypayment with no parameters: a bad request for whoever is let in, a refusal for the rest.
      const verify = await get(page, routeUrl('verifypayment'));
      if (mayUse('verifypayment')) {
        expect(verify.status, who('verifypayment')).toBe(400);
        expect(verify.body.trim(), who('verifypayment')).toBe('');
      } else if (wordpress) {
        expect(isRefusal(verify), who('verifypayment')).toBe(true);
      }
      expect(withoutCoreNoise(verify.body), who('verifypayment')).not.toMatch(PHP_OUTPUT);

      // savetoken without a processor: the window-close script for whoever is let in.
      const save = await get(page, routeUrl('savetoken'));
      if (mayUse('savetoken')) {
        expect(save.status, who('savetoken')).toBe(200);
        expect(save.body.trim(), who('savetoken')).toBe('<script>window.close();</script>');
      } else if (wordpress) {
        expect(isRefusal(save), who('savetoken')).toBe(true);
      }
      expect(withoutCoreNoise(save.body), who('savetoken')).not.toMatch(PHP_OUTPUT);

      // createtoken: a script's POST with every billing field. Staff with CiviContribute access get the hosted page.
      const create = await post(page, routeUrl('createtoken', { contact_id: contact.id, pp_id: live }), billingForm());
      if (mayUse('createtoken')) {
        expect(create.status, who('createtoken')).toBe(200);
        expect(JSON.parse(create.body), who('createtoken')).toMatchObject({ is_error: 0 });
      } else {
        expect(isRefusal(create), who('createtoken')).toBe(true);
        expect(isJsonText(create.body), `${who('createtoken')}: not an answer a script could use`).toBe(false);
        expect(create.body, who('createtoken')).not.toMatch(/eway-stub\.test/);
      }
      expect(withoutCoreNoise(create.body), who('createtoken')).not.toMatch(PHP_OUTPUT);

      // The settings page, by its public address and by its back-office one.
      for (const [name, url] of [['public', routeUrl('settings')], ['back office', adminUrl('ewayrecurring/settings', { reset: 1 })]] as const) {
        const settings = await get(page, url);
        const label = `${who('settings')} (${name} address)`;
        if (mayUse('settings')) {
          expect(settings.status, label).toBe(200);
          expect(settings.body, label).toContain('eway_recurring_contribution_max_retry');
        } else {
          expect(isRefusal(settings), label).toBe(true);
          expect(settings.body, `${label}: no form fields`).not.toContain('eway_recurring_contribution_max_retry');
        }
        expect(withoutCoreNoise(settings.body), label).not.toMatch(PHP_OUTPUT);
      }
    }
  });

  test('PW-S-03 a settings form posted by anyone but an administrator is refused and changes nothing', async ({ anonPage, financePage, tokenviewerPage, limitedPage }) => {
    const before = storedSettings();
    const form = {
      eway_recurring_contribution_max_retry: '99',
      eway_recurring_contribution_retry_delay: '99',
      eway_settlement_window_days: '99',
      eway_recurring_keep_sending_receipts: '1',
      _qf_Settings_submit: 'Submit',
      entryURL: routeUrl('settings'),
    };
    for (const [name, page] of [['anon', anonPage], ['finance', financePage], ['tokenviewer', tokenviewerPage], ['limited', limitedPage]] as const) {
      for (const url of [routeUrl('settings'), adminUrl('ewayrecurring/settings', { reset: 1 })]) {
        const result = await post(page, url, form, {});
        expect(isRefusal(result), `${name}: ${url}`).toBe(true);
        expect(withoutCoreNoise(result.body), `${name}: nothing printed`).not.toMatch(PHP_OUTPUT);
      }
    }
    expect(storedSettings()).toEqual(before);
  });

  test('PW-S-03 the route removed with the unused page (DEF-13) is served as an unknown route, with no form and no card data', async ({ anonPage, adminPage }) => {
    for (const [name, page] of [['anon', anonPage], ['admin', adminPage]] as const) {
      const removed = await get(page, frontUrl('ewayrecurring/verifyupdatetoken', { cs: 'x', cid: 1 }));
      const unknown = await get(page, frontUrl('ewayrecurring/no-such-route', { cs: 'x', cid: 1 }));
      expect(removed.status, name).toBe(unknown.status);
      expect(withoutCoreNoise(removed.body), `${name}: nothing printed`).not.toMatch(PHP_OUTPUT);
      // Core's own handling of a path with no menu entry: the same page for both.
      expect(visibleText(removed.body), name).toBe(visibleText(unknown.body));
      expect(removed.body, `${name}: no card form`).not.toMatch(/contact_payment_token|UpdateBilling/);
    }
  });
});

test.describe('the APIs the extension adds', () => {
  /** The rows in the verification queue (APIv3 only), with their access codes. */
  function queue(): { id: string; access_code: string }[] {
    return Object.values(evaluateJson<Record<string, { id: string; access_code: string }>>(
      "echo json_encode(civicrm_api3('EwayContributionTransactions', 'get', ['options' => ['limit' => 0]])['values']);",
    ));
  }

  /** An APIv3 call through the ajax route, as a script on a page makes it (a POST with the XHR header). */
  function rest(page: Page, entity: string, action: string, params: Record<string, unknown>): Promise<Answer> {
    return post(page, frontUrl('ajax/rest'), { entity, action, json: JSON.stringify(params) }, XHR);
  }

  test('PW-S-03 a visitor and every persona but the administrator are refused every call to the extension\'s own API entities, and nothing changes', async ({ anonPage, financePage, tokenviewerPage, limitedPage }) => {
    const waiting = createPendingPayment({ label: 's03api', amount: 25 });
    const rows = queue();
    const codes = rows.map((row) => row.access_code);
    expect(codes).toContain(waiting.accessCode);
    const processors = seededProcessors();

    const calls: [string, string, Record<string, unknown>][] = [
      ['EwayContributionTransactions', 'get', { options: { limit: 0 } }],
      ['EwayContributionTransactions', 'create', { contribution_id: waiting.contributionId, payment_processor_id: processors.live, access_code: 'ATTACKER-CODE', status: 0 }],
      ['EwayContributionTransactions', 'create', { id: waiting.queueId, status: 1 }],
      ['EwayContributionTransactions', 'delete', { id: waiting.queueId }],
      ['EwayContributionTransactions', 'validate', {}],
      ['EwayRecurring', 'fillTokensMeta', {}],
      ['EwaySettlement', 'Sync', { contribution_id: waiting.contributionId }],
    ];

    for (const [name, page] of [['anon', anonPage], ['finance', financePage], ['tokenviewer', tokenviewerPage], ['limited', limitedPage]] as const) {
      for (const [entity, action, params] of calls) {
        const label = `${name}: ${entity}.${action}`;
        const result = await rest(page, entity, action, params);
        expect(withoutCoreNoise(result.body), `${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
        for (const code of codes) {
          expect(result.body, `${label}: no access code in the answer`).not.toContain(code);
        }
        if (name === 'anon') {
          // The ajax route itself needs a signed-in user for these.
          expect(result.status, label).toBeGreaterThanOrEqual(400);
        } else {
          // CiviCRM answers an API refusal with a JSON error, status 200.
          expect(json(result), label).toMatchObject({ is_error: 1, error_code: 'unauthorized' });
        }
      }
    }

    // The queue is exactly as it was: no row added, changed or deleted.
    expect(queue().map((row) => `${row.id}:${row.access_code}`).sort()).toEqual(rows.map((row) => `${row.id}:${row.access_code}`).sort());
    expect(api4('Contribution', 'get', { select: ['contribution_status_id:name'], where: [['id', '=', waiting.contributionId]] })[0]['contribution_status_id:name']).toBe('Pending');
  });

  test('PW-S-03 a visitor is refused the token and processor entities through APIv4, and no card or processor is changed', async ({ anonPage }) => {
    const processors = seededProcessors();
    const contact = newContact('s03v4');
    const cards = tokenTotal();
    const processorCount = api4('PaymentProcessor', 'get', { select: ['id'], where: [['is_test', 'IN', [true, false]]] }).length;

    const calls: [string, string, Record<string, unknown>][] = [
      ['PaymentToken', 'get', { select: ['id', 'token', 'masked_account_number'] }],
      ['PaymentToken', 'create', { values: { contact_id: contact.id, payment_processor_id: processors.live, token: 'attacker-token' } }],
      ['PaymentToken', 'update', { values: { masked_account_number: 'CHANGED' }, where: [['id', '>', 0]] }],
      ['PaymentToken', 'delete', { where: [['id', '>', 0]] }],
      ['PaymentProcessor', 'get', { select: ['id', 'name', 'user_name', 'password'] }],
      ['PaymentProcessor', 'update', { values: { user_name: 'attacker' }, where: [['id', '>', 0]] }],
      ['ContributionRecur', 'get', { select: ['id', 'payment_token_id', 'processor_id'] }],
    ];
    for (const [entity, action, params] of calls) {
      for (const headers of [XHR, {}]) {
        const label = `${entity}.${action}${Object.keys(headers).length ? '' : ' (no XHR header)'}`;
        const result = await post(anonPage, frontUrl(`ajax/api4/${entity}/${action}`), { params: JSON.stringify(params) }, headers);
        expect(result.status, label).toBeGreaterThanOrEqual(400);
        expect(result.body, `${label}: no card or processor data`).not.toMatch(/"token"|masked_account_number|user_name|"password"/);
        expect(withoutCoreNoise(result.body), `${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      }
    }
    expect(tokenTotal()).toBe(cards);
    expect(api4('PaymentProcessor', 'get', { select: ['id'], where: [['is_test', 'IN', [true, false]]] })).toHaveLength(processorCount);
    expect(api4('PaymentProcessor', 'get', { select: ['user_name'], where: [['user_name', '=', 'attacker']] })).toHaveLength(0);
  });
});

/** Parse an answer as JSON. */
function json(result: Answer): any {
  try {
    return JSON.parse(result.body);
  } catch {
    throw new Error(`Not JSON (status ${result.status}): ${result.body.slice(0, 300)}`);
  }
}
