import { expect, type Locator } from '@playwright/test';

/**
 * Checks that a message really shows to the donor inside the CMS theme, not just that its text is in the page.
 * `toContainText` passes for text that is hidden, transparent, off to one side or covered, so a theme that hides
 * CiviCRM's status messages would go unnoticed. This looks at what the browser draws:
 *
 *  - the element is visible and has size, and is not faded out;
 *  - it sits inside the page's width (not pushed off to one side) and does not start above the top of the page;
 *  - its text is readable against its own background, or the nearest ancestor that has one (contrast ratio 3 or more,
 *    the WCAG minimum for large text, as a floor that catches white on white and similar);
 *  - nothing else is drawn over its middle.
 */
export async function expectShownInTheme(locator: Locator, what: string): Promise<void> {
  await expect(locator.first(), `${what} is visible`).toBeVisible();
  await locator.first().scrollIntoViewIfNeeded();

  const result = await locator.first().evaluate((element) => {
    const parse = (color: string): number[] | null => {
      const match = color.match(/rgba?\(([^)]+)\)/);
      if (!match) {
        return null;
      }
      const parts = match[1].split(',').map((part) => parseFloat(part));
      return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
    };
    const luminance = ([r, g, b]: number[]): number => {
      const channel = (value: number) => {
        const v = value / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };

    const style = getComputedStyle(element);
    // The first background that is not transparent, walking up from the element, then the page's white.
    let background: number[] = [255, 255, 255, 1];
    for (let node: Element | null = element; node; node = node.parentElement) {
      const color = parse(getComputedStyle(node).backgroundColor);
      if (color && color[3] > 0.5) {
        background = color;
        break;
      }
    }
    const foreground = parse(style.color) || [0, 0, 0, 1];
    const lighter = Math.max(luminance(foreground), luminance(background));
    const darker = Math.min(luminance(foreground), luminance(background));

    const box = element.getBoundingClientRect();
    const middle = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
    return {
      opacity: parseFloat(style.opacity),
      // Page coordinates, so scrolling the element into view cannot hide that it is out to one side.
      left: box.left + window.scrollX,
      right: box.right + window.scrollX,
      width: box.width,
      height: box.height,
      topOnPage: box.top + window.scrollY,
      viewportWidth: document.documentElement.clientWidth,
      contrast: (lighter + 0.05) / (darker + 0.05),
      // True when what is drawn at the element's middle is the element, something inside it, or something it sits in.
      onTop: !!middle && (element.contains(middle) || middle.contains(element)),
    };
  });

  expect(result.width, `${what} has a width`).toBeGreaterThan(0);
  expect(result.height, `${what} has a height`).toBeGreaterThan(0);
  expect(result.opacity, `${what} is not faded out`).toBeGreaterThan(0.9);
  expect(result.left, `${what} is not pushed off the left edge`).toBeGreaterThanOrEqual(-1);
  expect(result.right, `${what} is not pushed off the right edge`).toBeLessThanOrEqual(result.viewportWidth + 1);
  expect(result.topOnPage, `${what} is not above the top of the page`).toBeGreaterThanOrEqual(0);
  expect(result.contrast, `${what} can be read against its background (contrast ${result.contrast.toFixed(2)})`).toBeGreaterThanOrEqual(3);
  expect(result.onTop, `${what} is not covered by something else`).toBe(true);
}
