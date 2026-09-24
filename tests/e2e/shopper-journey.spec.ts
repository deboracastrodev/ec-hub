import { expect, test, type Page } from '@playwright/test';

type Recommendation = { product_id: number | string };

async function fetchRecommendationIds(page: Page, productId: number): Promise<number[]> {
  // page.request compartilha os cookies do contexto: as recomendações são
  // personalizadas pela sessão do navegador, então um request isolado não serve.
  const response = await page.request.get(`/api/recommendations?product_id=${productId}&limit=5`);
  expect(response.status(), 'GET /api/recommendations deve responder 200').toBe(200);

  const body = (await response.json()) as { data: Recommendation[] };
  expect(Array.isArray(body.data)).toBe(true);

  return body.data.map((item) => Number(item.product_id));
}

async function currentProductId(page: Page): Promise<number> {
  const raw = await page.locator('[data-add-cart]').getAttribute('data-add-cart');
  expect(raw, 'página de detalhe deve expor o id do produto').not.toBeNull();
  const id = Number(raw);
  expect(Number.isInteger(id) && id > 0).toBe(true);

  return id;
}

type Journey = { productA: number; productB: number; l1: number[]; l2: number[] };

/**
 * Jornada da Ana: abre o 1º produto (A), pega as recomendações (L1), abre o
 * último recomendado (B), adiciona B ao carrinho e pega as recomendações de
 * novo (L2) -- tudo na sessão (cookie) do contexto do teste.
 */
async function runShopperJourney(page: Page): Promise<Journey> {
  await page.goto('/products');
  const firstCard = page.locator('a.product-card__link').first();
  await expect(firstCard).toBeVisible();
  await Promise.all([page.waitForURL(/\/products\/[A-Za-z0-9-]+$/), firstCard.click()]);
  const productA = await currentProductId(page);

  const l1 = await fetchRecommendationIds(page, productA);
  expect(l1.length, 'L1 precisa de ao menos 2 itens para a mudança ser observável').toBeGreaterThanOrEqual(2);

  // B = último item de L1 (ver Design Notes da spec 6-4).
  const productB = l1[l1.length - 1];
  expect(productB, 'B precisa ser diferente de A').not.toBe(productA);
  await page.goto(`/products/${productB}`);
  expect(await currentProductId(page)).toBe(productB);

  const cartResponse = page.waitForResponse(
    (response) => response.url().endsWith('/api/cart/items') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Adicionar ao carrinho' }).click();
  expect((await cartResponse).status()).toBe(200);

  const l2 = await fetchRecommendationIds(page, productA);

  return { productA, productB, l1, l2 };
}

test('Ana: recomendações mudam após ver e adicionar um produto ao carrinho', async ({ page }) => {
  const { productB, l1, l2 } = await runShopperJourney(page);

  expect(l2).not.toEqual(l1);
  expect(l2[0]).toBe(productB);
});

test('Ana: /metrics mostra o histórico da sessão e a mudança da recomendação', async ({ page }) => {
  const { productA, productB, l1, l2 } = await runShopperJourney(page);
  expect(l2).not.toEqual(l1);

  await page.goto('/metrics');
  const history = page.getByRole('region', { name: 'Histórico de eventos' });
  await expect(history).toBeVisible();
  await expect(history.getByText(`Produto: ${productA}`, { exact: true }).first()).toBeVisible();
  await expect(history.getByText(`Produto: ${productB}`, { exact: true }).first()).toBeVisible();

  const countText = (await page.locator('#event-history-count').textContent()) ?? '';
  const match = countText.match(/Total de eventos:\s*(\d+)/);
  expect(match, `contagem inesperada: "${countText}"`).not.toBeNull();
  expect(Number(match![1])).toBeGreaterThanOrEqual(3);

  await page.getByText('Ver detalhes da sessão atual', { exact: true }).click();
  await expect(page.getByText('A recomendação mudou nesta sessão.', { exact: true })).toBeVisible();
});

test('/metrics com sessão nova não mostra eventos', async ({ page }) => {
  await page.goto('/metrics');

  const history = page.getByRole('region', { name: 'Histórico de eventos' });
  await expect(history.getByText('Nenhum evento foi registrado nesta sessão.', { exact: true })).toBeVisible();
  await expect(page.locator('#event-history-count')).toHaveText('Total de eventos: 0');
});
