import test from 'node:test';
import assert from 'node:assert/strict';
import { cropRectangle } from '../../resources/js/banner-editor.js';

test('one landscape source yields the desktop tablet and mobile crops without distortion', () => {
    assert.deepEqual(cropRectangle(1800, 1200, 1440, 480, 1, 0.5, 0.5), { x: 0, y: 300, width: 1800, height: 600 });
    assert.deepEqual(cropRectangle(1800, 1200, 1024, 512, 1, 0.5, 0.5), { x: 0, y: 150, width: 1800, height: 900 });
    assert.deepEqual(cropRectangle(1800, 1200, 720, 540, 1, 0.5, 0.5), { x: 100, y: 0, width: 1600, height: 1200 });
});

test('portrait image can be positioned and zoomed without cropping outside source bounds', () => {
    assert.deepEqual(cropRectangle(600, 1200, 1440, 480, 1, 0, 1), { x: 0, y: 1000, width: 600, height: 200 });
    assert.deepEqual(cropRectangle(600, 1200, 1440, 480, 2, 1, 0), { x: 300, y: 0, width: 300, height: 100 });
});
