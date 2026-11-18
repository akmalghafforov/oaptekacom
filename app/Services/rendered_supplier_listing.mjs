import { chromium } from 'playwright';

export function listingRequestAllowed(raw, resourceType, source) {
    const url = new URL(raw);
    if (resourceType === 'document') {
        return source === 'icorp'
            ? url.hostname === 'icorp.tech' && url.pathname === '/listpartners'
            : url.hostname === 'pricelist-tj.com' && url.pathname === '/companies';
    }
    // Allow the rendered listing's data calls, never a company's details or catalog.
    return ! /\/(?:priceview|catalog(?:s)?|products?|items|prices)(?:\/|$)|\/companies\/[^/?]+|\/company\/[^/?]+/i.test(url.pathname);
}

export function extractListing(source) {
    const visible = element => element.getClientRects().length > 0;
    const text = element => (element.innerText ?? '').replace(/\s+/g, ' ').trim();
    const reactKey = element => {
        const property = Object.keys(element).find(key => key.startsWith('__reactFiber$'));
        let fiber = property ? element[property] : null;
        for (let depth = 0; fiber && depth < 8; depth++, fiber = fiber.return) {
            if (fiber.stateNode instanceof Element && fiber.stateNode !== element) return null;
            if (fiber.key === null || fiber.key === undefined) continue;
            const key = String(fiber.key);
            // The nearest keyed component owns the card; never borrow a page key.
            return /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,254}$/.test(key)
                && !/^(?:page|pagination|next|previous|prev)(?:[-_]?\d*)$/i.test(key) ? key : null;
        }
        return null;
    };
    let cards;
    if (source === 'icorp') {
        cards = [...document.querySelectorAll('article[data-partner-id]')].filter(visible);
    } else {
        cards = [...document.querySelectorAll('button')].filter(element => visible(element) && /Обновлено\s*:|Прайс ещё не загружен/i.test(text(element)));
        if (!cards.length) {
            const labels = [...document.querySelectorAll('body *')].filter(element => visible(element) && /Обновлено\s*:/i.test(text(element)) && ![...element.children].some(child => /Обновлено\s*:/i.test(text(child))));
            cards = labels.map(label => {
                let card = label;
                while (card.parentElement && (!card.querySelector('h2,h3,h4,span.font-bold') || !reactKey(card))) card = card.parentElement;
                return card;
            });
        }
        cards = [...new Set(cards)];
    }
    const partners = cards.map(card => {
        const content = text(card);
        const match = content.match(source === 'icorp' ? /Дата прайса\s*:?\s*(\d{2}\.\d{2}\.\d{4})/i : /Обновлено\s*:?\s*(\d{2}\.\d{2}\.\d{4},?\s+\d{2}:\d{2})/i);
        const price = source === 'icorp' ? card.querySelector('a[href*="/priceview/"]') : null;
        const name = text(card.querySelector('h2,h3,h4,span.font-bold') ?? card.querySelector('strong') ?? card);
        const city = (source === 'pricelist_tj' ? text(card.querySelector('div.font-mono2') ?? document.createElement('span')) : '') || (content.match(/(?:Город|г\.)\s*:?\s*([^,;]+?)(?=\s+(?:Тел|Обновлено|Дата)|$)/i)?.[1] ?? null);
        return { source, external_id: source === 'icorp' ? card.getAttribute('data-partner-id') : reactKey(card), name,
            phones: [...card.querySelectorAll('a[href^="tel:"]')].map(link => link.getAttribute('href').slice(4)),
            ...(city ? {city} : {}), published_at: match?.[1] ?? null, raw_date: match?.[1] ?? null,
            available: source === 'icorp' ? Boolean(price) : !/прайс (?:отсутствует|недоступен|ещё не загружен)/i.test(content),
            catalog_id: price ? new URL(price.href).pathname.split('/').pop() : null,
            listing_text: content };
    });
    if (partners.some(partner => !partner.external_id)) throw new Error('unresolved_supplier_id');
    const controls = [...document.querySelectorAll('button,a')].filter(visible);
    const pageNumbers = controls.map(element => text(element)).filter(value => /^\d+$/.test(value)).map(Number);
    const body = document.body.innerText;
    const pagination = body.match(/Страница\s+\d+\s+из\s+(\d+)/i) ?? body.match(/(?:^|\n)\s*\d+\s*\/\s*(\d+)\s*(?:$|\n)/);
    const total = source === 'pricelist_tj' ? body.match(/Найдено:\s*(\d+)/i)?.[1] : null;
    return { partners, total_pages: pagination ? Number(pagination[1]) : Math.max(1, ...pageNumbers), received: partners.length, total: total === undefined || total === null ? null : Number(total) };
}

export function listingPageChanged({source, previous}) {
    const cards = [...document.querySelectorAll(source === 'icorp' ? 'article[data-partner-id]' : 'button')];
    const card = cards.find(element => element.getClientRects().length > 0 && (source === 'icorp' || /Обновлено\s*:|Прайс ещё не загружен/i.test(element.innerText)));
    return Boolean(card && card.innerText.replace(/\s+/g, ' ').trim() !== previous);
}

export async function dismissListingNotice(page) {
    const dismiss = page.getByRole('button', {name: 'Не сейчас', exact: true});
    if (await dismiss.isVisible()) await dismiss.click();
}

async function main() {
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const options = JSON.parse(input);
    const browser = await chromium.launch({headless: true, executablePath: options.executable_path ?? process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const context = await browser.newContext({locale: 'ru-RU', timezoneId: 'Asia/Dushanbe', userAgent: options.user_agent, storageState: { cookies: options.cookies ?? [], origins: [] }});
        if (options.token) {
            await context.addInitScript(token => {
                localStorage.setItem('pricelist_token', token);
            }, options.token);
        }
        await context.route('**/*', route => listingRequestAllowed(route.request().url(), route.request().resourceType(), options.source) ? route.continue() : route.abort());
        const page = await context.newPage();
        let sourceFailure = null;
        page.on('response', response => {
            const url = new URL(response.url());
            if (!['icorp.tech', 'pricelist-tj.com'].includes(url.hostname) || !['document', 'xhr', 'fetch'].includes(response.request().resourceType())) return;
            if ([401, 403, 409, 419].includes(response.status())) sourceFailure = response.status() === 403 ? 'permission' : response.status() === 409 ? 'takeover_required' : 'authentication';
            if (response.status() === 429) sourceFailure = `rate_limited:${Number(response.headers()['retry-after']) || 3600}`;
        });
        const url = options.source === 'icorp' ? 'https://icorp.tech/listpartners' : 'https://pricelist-tj.com/companies';
        const response = await page.goto(url, {waitUntil: 'domcontentloaded', timeout: 45000});
        if (response?.status() === 429) throw new Error(`rate_limited:${Number(response.headers()['retry-after']) || 3600}`);
        if ([401,403,409,419].includes(response?.status()) || page.url().split('?')[0] !== url) throw new Error('authentication');
        try {
            await page.waitForFunction(source => source === 'icorp' ? document.querySelectorAll('article[data-partner-id]').length > 0 : /Обновлено\s*:|Прайс ещё не загружен/i.test(document.body.innerText), options.source, {timeout: 20000});
        } catch (error) {
            if (sourceFailure) throw new Error(sourceFailure);
            if (await page.locator('input[type="password"]').count()) throw new Error('authentication');
            throw error;
        }
        if (sourceFailure) throw new Error(sourceFailure);
        let totalPages = 1;
        for (let number = 1; number <= totalPages; number++) {
            if (number > 1) {
                if (options.source === 'pricelist_tj') await dismissListingNotice(page);
                const button = page.getByRole('button', {name: String(number), exact: true});
                const link = page.getByRole('link', {name: String(number), exact: true});
                const before = await page.evaluate(extractListing, options.source);
                if (await button.count()) {
                    await button.click();
                } else if (await link.count()) {
                    await link.click();
                } else {
                    const indicator = page.locator('span').filter({hasText: new RegExp(`^\\s*${number - 1}\\s*/\\s*${totalPages}\\s*$`)});
                    await indicator.locator('..').getByRole('button').last().click();
                }
                await page.waitForFunction(listingPageChanged, {source: options.source, previous: before.partners[0]?.listing_text}, {timeout: 20000});
            }
            if (options.pages?.[number]) {
                totalPages = options.pages[number].total_pages;
                continue;
            }
            const result = await page.evaluate(extractListing, options.source);
            if (!result.partners.length || result.partners.some(partner => !partner.external_id || !partner.name)) throw new Error('extraction');
            totalPages = result.total_pages;
            if (totalPages > 100) throw new Error('extraction');
            process.stdout.write(JSON.stringify({page: number, ...result}) + '\n');
        }
    } finally {
        await browser.close();
    }
}

if (process.argv[1] && import.meta.url === new URL(process.argv[1], 'file:').href) {
    main().catch(error => {
        const reason = /authentication/.test(error.message) ? 'authentication' : /permission/.test(error.message) ? 'permission' : /takeover_required/.test(error.message) ? 'takeover_required' : /rate_limited/.test(error.message) ? 'rate_limited' : /net::|timeout|timed out/i.test(error.message) ? 'transport' : 'extraction';
        process.stderr.write(JSON.stringify({reason, retry_after: reason === 'rate_limited' ? Number(error.message.split(':')[1]) || 3600 : null}));
        process.exitCode = 1;
    });
}
