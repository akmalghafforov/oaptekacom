import test from 'node:test';
import assert from 'node:assert/strict';
import { calendarDays, dateAllowed, dateBounds, displayDate, moveDate, parseDate } from '../../resources/js/date-picker.js';

for (const [value, expected] of [['08.11.2026', '2026-11-08'], ['2026-11-08', '2026-11-08'], ['29.02.2028', '2028-02-29'], ['29.02.2027', null], ['31.04.2026', null], ['11/08/2026', null], ['', null], ['2026-13-01', null], ['0000-01-01', null]]) {
    test(`calendar parses ${JSON.stringify(value)} without accepting invalid dates`, () => assert.equal(parseDate(value), expected));
}

test('display uses Russian date order while submitted values stay ISO', () => {
    assert.equal(displayDate('2026-11-08'), '08.11.2026');
    assert.equal(parseDate(displayDate('2026-11-08')), '2026-11-08');
    assert.equal(displayDate(''), '');
});

test('calendar starts on Monday and includes adjacent month days', () => {
    const days = calendarDays(2026, 10);
    assert.equal(days.length, 42);
    assert.equal(days[0], '2026-10-26');
    assert.equal(days[6], '2026-11-01');
    assert.equal(days.at(-1), '2026-12-06');
    assert.equal(new Set(days).size, 42);
    assert.ok(calendarDays(2028, 1).includes('2028-02-29'));
});

test('month and keyboard navigation cross year boundaries and clamp shorter months', () => {
    assert.equal(moveDate('2026-12-31', 1), '2027-01-01');
    assert.equal(moveDate('2026-11-02', -7), '2026-10-26');
    assert.equal(moveDate('2026-01-31', 1, 'month'), '2026-02-28');
    assert.equal(moveDate('2028-01-31', 1, 'month'), '2028-02-29');
    assert.equal(moveDate('2028-02-29', 12, 'month'), '2029-02-28');
    assert.equal(moveDate('2026-01-15', -1, 'month'), '2025-12-15');
});

test('start and end boundaries are inclusive and prevent earlier end dates', () => {
    assert.equal(dateAllowed('2026-11-08', '2026-11-08', '2026-11-30'), true);
    assert.equal(dateAllowed('2026-11-30', '2026-11-08', '2026-11-30'), true);
    assert.equal(dateAllowed('2026-11-07', '2026-11-08', null), false);
    assert.equal(dateAllowed('2026-12-01', null, '2026-11-30'), false);
    assert.equal(dateAllowed('2026-02-30', null, null), false);
});

test('linked minimum reads the submitted date by name instead of the display input with the same id', () => {
    const form = { elements: [{ id: 'starts_on', name: '', value: '13.11.2026' }, { name: 'starts_on', value: '2026-11-13' }] };
    const input = { form, min: '2026-11-01', max: '' };
    assert.deepEqual(dateBounds(input, 'starts_on'), ['2026-11-13', null]);
    input.min = '2026-11-20';
    assert.deepEqual(dateBounds(input, 'starts_on'), ['2026-11-20', null]);
    form.elements[1].value = '';
    assert.deepEqual(dateBounds(input, 'starts_on'), ['2026-11-20', null]);
});
