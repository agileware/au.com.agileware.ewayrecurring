import { expect, type Page } from '@playwright/test';
import { api4, evaluateJson } from './civi';
import { frontUrl } from './cms';
import { chooseOption, DEFAULT_DONOR, type Donor } from './contribution-page';
import { STUB_HOST } from './eway-stub';
import testData from './test-data.json';

/**
 * Helpers for the seeded events (see seed-data.php and test-data.json): a paid event that accepts up to two more
 * people on the same registration, and a free one. Both use CiviCRM's "Your Registration Info" profile (first
 * name, last name, email). The paid event shows the billing address on the first step; its confirm step has a
 * Continue button that sends the registrant to eWAY's hosted page.
 */

/** The id of an event, found by its title. */
function eventIdByTitle(title: string): number {
  const rows = api4<{ id: number }>('Event', 'get', { select: ['id'], where: [['title', '=', title]] });
  if (!rows.length) {
    throw new Error(`The seeded event "${title}" does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/** The id of the seeded paid event. */
export function paidEventId(): number {
  return eventIdByTitle(testData.events.paid.title);
}

/** The id of the seeded free event. */
export function freeEventId(): number {
  return eventIdByTitle(testData.events.free.title);
}

/** The ticket price of the paid event as the seed set it (before any tax the site adds). */
export const PAID_EVENT_FEE: number = testData.events.paid.fee;

/** The address of an event's registration form on the front end. */
export function eventUrl(eventId: number): string {
  return frontUrl('event/register', { reset: 1, id: eventId });
}

/** A person registering. The billing address is only used by the first (paying) person. */
export type Registrant = Donor;

/** Fill in the first and last name and email of the "Your Registration Info" profile on the current step. */
async function fillRegistrationProfile(page: Page, person: Registrant, emailSelector: string): Promise<void> {
  await page.locator('#first_name').fill(person.firstName);
  await page.locator('#last_name').fill(person.lastName);
  await page.locator(emailSelector).fill(person.email);
}

/** Choose the ticket (price option) whose label starts with this dollar amount. */
export async function chooseTicket(page: Page, fee: number): Promise<void> {
  const radio = page.getByRole('radio', { name: new RegExp(`\\$${fee}\\b`) });
  // The price set's script can redraw the options just after the page loads, which drops a first click.
  await expect(async () => {
    await radio.check();
    await expect(radio).toBeChecked({ timeout: 1000 });
  }).toPass({ timeout: 15000 });
}

/** Fill in the billing address block that sits under the fee on the first step. */
export async function fillEventBilling(page: Page, person: Registrant): Promise<void> {
  await page.locator('#billing_first_name').fill(person.firstName);
  await page.locator('#billing_last_name').fill(person.lastName);
  await page.locator('#billing_street_address-5').fill(person.street);
  await page.locator('#billing_city-5').fill(person.city);
  await chooseOption(page, '#billing_country_id-5', person.country);
  await chooseOption(page, '#billing_state_province_id-5', person.state);
  await page.locator('#billing_postal_code-5').fill(person.postcode);
}

/**
 * Fill in the first step of the paid event for the person registering, with `more` extra people to be asked for on
 * the next steps. Leaves the page ready to press Review.
 */
export async function fillPaidRegistration(page: Page, person: Registrant = DEFAULT_DONOR, more = 0): Promise<void> {
  await fillRegistrationProfile(page, person, '#email-Primary');
  await chooseTicket(page, PAID_EVENT_FEE);
  await fillEventBilling(page, person);
  if (more > 0) {
    // The select lists the number of people, and each option's value is one less (the extra people). It is set last
    // and without raising the change event: the server reads the value when the form is posted, and a site's other
    // extensions (a discount script, say) can have their own change handlers on this select that fail on a state
    // nobody asked for.
    await page.locator('#additional_participants').evaluate((el, extra) => {
      (el as HTMLSelectElement).value = String(extra);
    }, more);
  }
}

/** Fill in a free registration's first step. Leaves the page ready to press Review. */
export async function fillFreeRegistration(page: Page, person: Registrant = DEFAULT_DONOR): Promise<void> {
  await fillRegistrationProfile(page, person, '#email-Primary');
}

/**
 * Fill in the form for one of the extra people (the "Participant" steps) and press its Review button. Waits for the
 * next step to load (another participant, or the confirm step).
 */
export async function fillAdditionalParticipant(page: Page, person: Registrant): Promise<void> {
  await fillRegistrationProfile(page, person, 'input[id^="email-"]:visible');
  await chooseTicket(page, PAID_EVENT_FEE);
  // The address can stay the same from one step to the next, so a mark on the old document shows the page changed.
  await page.evaluate(() => {
    (window as any).__beforeContinue = true;
  });
  // The button says Continue, or Review on the last person.
  await page.getByRole('button', { name: /^(Continue|Review)$/ }).click();
  await page.waitForFunction(() => !(window as any).__beforeContinue);
  await page.waitForLoadState('load');
}

/** The "Total for this participant" the form shows after a ticket is chosen, in dollars. */
export async function participantTotal(page: Page): Promise<number> {
  const text = (await page.locator('#pricevalue').innerText()).replace(/[^0-9.]/g, '');
  return Number(text);
}

/** Press Review on the first step. */
export async function pressReview(page: Page): Promise<void> {
  await page.locator('#_qf_Register_upload-bottom').click();
}

/** On the confirm step, press Continue and wait for eWAY's hosted page (the fake one). */
export async function continueFromEventConfirm(page: Page): Promise<void> {
  await page.locator('#_qf_Confirm_next-bottom').click();
  await page.waitForURL((url) => url.hostname === STUB_HOST);
}

/** The participants a person's email address has, oldest first, with their status and the event. */
export function participantsOf(email: string): Record<string, any>[] {
  return api4('Participant', 'get', {
    select: ['id', 'status_id:name', 'event_id', 'contact_id', 'fee_amount', 'role_id', 'registered_by_id'],
    where: [['contact_id.email_primary.email', '=', email]],
    orderBy: { id: 'ASC' },
  });
}

/**
 * The contribution that pays for a participant, found through the participant payment link (APIv3 only), if there
 * is one: id, status name, total amount, transaction id and invoice id.
 */
export function contributionOfParticipant(participantId: number): Record<string, any> | undefined {
  return evaluateJson<Record<string, any> | null>(`
    $links = civicrm_api3('ParticipantPayment', 'get', ['participant_id' => ${Number(participantId)}, 'options' => ['limit' => 1]])['values'];
    if (!$links) { echo json_encode(NULL); return; }
    $link = reset($links);
    $c = civicrm_api4('Contribution', 'get', ['checkPermissions' => FALSE, 'where' => [['id', '=', $link['contribution_id']]],
      'select' => ['id', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'invoice_id']])->first();
    echo json_encode(['id' => $c['id'], 'status' => $c['contribution_status_id:name'], 'total_amount' => $c['total_amount'], 'trxn_id' => $c['trxn_id'], 'invoice_id' => $c['invoice_id']]);
  `) ?? undefined;
}
