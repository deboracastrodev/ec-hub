import { expect, test } from '@playwright/test';

test('Carlos: /health reporta aplicação e serviços no ar', async ({ page }) => {
  const response = await page.goto('/health');

  expect(response, 'GET /health deve responder').not.toBeNull();
  expect(response!.status()).toBe(200);

  const body = await response!.json();
  expect(body.status).toBe('healthy');
  expect(body.services.mysql.status).toBe('up');
  expect(body.services.redis.status).toBe('up');
});
