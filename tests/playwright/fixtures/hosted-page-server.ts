import { createServer, type Server } from 'node:http';
import { hostedPageHtml, HOSTED_PORT } from './eway-stub';

/**
 * Serves the fake eWAY hosted payment page (see eway-stub.ts). Started once for the run by global-setup.ts, on
 * 127.0.0.1; the browser reaches it as eway-stub.test through a host resolver rule in playwright.config.ts.
 */
export function startHostedPageServer(): Promise<Server> {
  const server = createServer((request, response) => {
    if (request.url && request.url.startsWith('/pay')) {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(hostedPageHtml());
      return;
    }
    response.writeHead(404, { 'Content-Type': 'text/plain' });
    response.end('Not found');
  });
  return new Promise((resolve, reject) => {
    server.once('error', (error: NodeJS.ErrnoException) => {
      reject(
        error.code === 'EADDRINUSE'
          ? new Error(`Port ${HOSTED_PORT} is in use. Set EWAY_STUB_PORT to a free port.`)
          : error,
      );
    });
    server.listen(HOSTED_PORT, '127.0.0.1', () => resolve(server));
  });
}
