import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { extractListing, listingRequestAllowed, listingPageChanged, dismissListingNotice } from '../../app/Services/rendered_supplier_listing.mjs';

for (const path of ['/priceview/42', '/api/catalog/42', '/api/companies/42', '/api/products', '/company/42', '/api/prices/company/42']) {
    test(`listing scan blocks supplier/catalog request ${path}`, () => {
        assert.equal(listingRequestAllowed(`https://pricelist-tj.com${path}`, 'fetch', 'pricelist_tj'), false);
    });
}
test('listing data calls remain available to the rendered application', () => {
    assert.equal(listingRequestAllowed('https://pricelist-tj.com/api/companies?page=2', 'fetch', 'pricelist_tj'), true);
    assert.equal(listingRequestAllowed('https://icorp.tech/listpartners', 'document', 'icorp'), true);
    assert.equal(listingRequestAllowed('https://icorp.tech/priceview/1', 'document', 'icorp'), false);
});

test('rendered iCorp cards use visible date and never data attributes for dates', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage({locale: 'ru-RU', timezoneId: 'Asia/Dushanbe'});
        await page.setContent(`<article data-partner-id="43" data-price-date="1999-01-01"><h3>Форс</h3><p>Дата прайса: 18.11.2026</p><a href="tel:+992111111111">Позвонить</a><a href="https://icorp.tech/priceview/88">Прайс</a></article><article data-partner-id="44"><h3>Без даты</h3><p>Дата прайса: —</p></article>`);
        const result = await page.evaluate(extractListing, 'icorp');
        assert.equal(result.partners.length, 2);
        assert.equal(result.partners[0].external_id, '43');
        assert.equal(result.partners[0].published_at, '18.11.2026');
        assert.equal(result.partners[0].catalog_id, '88');
        assert.equal(result.partners[1].published_at, null);
        assert.equal(result.partners[1].available, false);
        assert.equal(result.total_pages, 1);
    } finally { await browser.close(); }
});

test('rendered React cards expose supplier keys, local dates, and pagination', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage({locale: 'ru-RU', timezoneId: 'Asia/Dushanbe'});
        await page.setContent('<main><section id="card"><h3>Мижгона</h3><p>Город: Худжанд</p><p>Обновлено: 18.11.2026, 00:05</p></section><button>1</button><button>2</button></main>');
        await page.evaluate(() => { document.querySelector('#card').__reactFiber$fixture = {key: '35', return: null}; });
        const result = await page.evaluate(extractListing, 'pricelist_tj');
        assert.equal(result.partners.length, 1);
        assert.equal(result.partners[0].external_id, '35');
        assert.equal(result.partners[0].published_at, '18.11.2026, 00:05');
        assert.equal(result.total_pages, 2);
        assert.equal(await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone), 'Asia/Dushanbe');
    } finally { await browser.close(); }
});

test('deployed-style button cards retain unavailable suppliers and read arrow pagination totals', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage({locale: 'ru-RU', timezoneId: 'Asia/Dushanbe'});
        await page.setContent('<p>Найдено: 35 · Страница 1 из 2</p><button id="first"><span class="font-bold">Форс</span><div class="font-mono2">Худжанд</div><div>Обновлено: 18.11.2026, 10:05</div></button><button id="second"><span class="font-bold">Без прайса</span><div>Прайс ещё не загружен</div></button><div><button disabled>‹</button><span>1 / 2</span><button>›</button></div>');
        await page.evaluate(() => {
            document.querySelector('#first').__reactFiber$fixture = {key: '1', return: null};
            document.querySelector('#second').__reactFiber$fixture = {key: '2', return: null};
        });
        const result = await page.evaluate(extractListing, 'pricelist_tj');
        assert.equal(result.partners.length, 2);
        assert.equal(result.partners[0].name, 'Форс');
        assert.equal(result.partners[0].city, 'Худжанд');
        assert.equal(result.partners[1].available, false);
        assert.equal(result.partners[1].published_at, null);
        assert.equal(result.total_pages, 2);
        assert.equal(result.total, 35);
    } finally { await browser.close(); }
});

test('text supplier keys survive rendered card extraction without borrowing pagination keys', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage();
        await page.setContent('<main><button id="supplier"><span class="font-bold">Азия Фарм</span><div>Обновлено: 18.11.2026, 10:05</div></button><button id="pagination">2</button></main>');
        await page.evaluate(() => {
            document.querySelector('#supplier').__reactFiber$fixture = {key: null, return: {key: 'cmvfko9dy0000leh93jjp3aeo', return: {key: 'page-1'}}};
            document.querySelector('#pagination').__reactFiber$fixture = {key: 'page-2'};
        });
        const result = await page.evaluate(extractListing, 'pricelist_tj');
        assert.equal(result.partners.length, 1);
        assert.equal(result.partners[0].external_id, 'cmvfko9dy0000leh93jjp3aeo');
        await page.evaluate(() => { document.querySelector('#supplier').__reactFiber$fixture = {key: 'page-2', return: {key: 'cmvfko9dy0000leh93jjp3aeo'}}; });
        await assert.rejects(page.evaluate(extractListing, 'pricelist_tj'), /unresolved_supplier_id/);
        await page.evaluate(() => { document.querySelector('#supplier').__reactFiber$fixture = {key: null, return: {key: '2', stateNode: document.querySelector('main')}}; });
        await assert.rejects(page.evaluate(extractListing, 'pricelist_tj'), /unresolved_supplier_id/);
        await page.evaluate(() => { delete document.querySelector('#supplier').__reactFiber$fixture; });
        await assert.rejects(page.evaluate(extractListing, 'pricelist_tj'), /unresolved_supplier_id/);
    } finally { await browser.close(); }
});


test('pagination waits for supplier cards rather than the page indicator changing', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage();
        await page.setContent('<button id="supplier"><span>First supplier</span><div>Обновлено: 18.11.2026, 10:05</div></button><span id="pagination">1 / 2</span>');
        const previous = 'First supplier Обновлено: 18.11.2026, 10:05';
        await page.locator('#pagination').evaluate(element => element.textContent = '2 / 2');
        assert.equal(await page.evaluate(listingPageChanged, {source: 'pricelist_tj', previous}), false);
        await page.locator('#supplier span').evaluate(element => element.textContent = 'Second supplier');
        assert.equal(await page.evaluate(listingPageChanged, {source: 'pricelist_tj', previous}), true);
    } finally { await browser.close(); }
});


test('listing pagination dismisses the promotional notice that blocks pointer events', {skip: !process.env.RUN_LISTING_BROWSER_TESTS}, async () => {
    const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage();
        await page.setContent('<button id="next" onclick="this.textContent=\'Page 2\'">Next page</button><div style="position:fixed;inset:0;background:white"><button onclick="this.parentElement.remove()">Не сейчас</button></div>');
        await dismissListingNotice(page);
        await page.locator('#next').click({timeout: 2000});
        assert.equal(await page.locator('#next').innerText(), 'Page 2');
        await dismissListingNotice(page);
    } finally { await browser.close(); }
});
