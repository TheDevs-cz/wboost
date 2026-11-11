// Background panel „Pozadí“ (handoff §6.1 "Background panel", map §8.8). Owner: P5a; reused for every `Fill`.
//
//   fillPanel({ value, onChange, onStart?, onCommit?, ctx, path, label?, image? }) → HTMLElement `.cal-psec.cal-bgp`
//
//   value      `Fill` of architecture §B.2:
//              { colorOn, color, colorOpacity, gradient: { on, type, angle, stops }, imageOn, asset, fit, imageOpacity }
//   onChange   `(fill) => void` — always the complete next Fill
//   onStart / onCommit   bracket a slider drag (Krytí, Úhel) — one undo step; pass a `styleBinding` (`bind.js`):
//                  const b = styleBinding(store, 'month.bg');
//                  fillPanel({ value: b.get(), ctx, path: 'month.bg', ...b })
//   ctx        `Ctx` (uploads + `env.palette`)
//   path       style path of the Fill → `data-prop` hooks `<path>.colorOn`, `.color`, `.colorOpacity`,
//              `.gradient.on`, `.gradient.*` (see gradient.js), `.imageOn`, `.asset`, `.fit`, `.imageOpacity`
//   label      section label (default `Pozadí`; `null` = none)
//   image      `{ tip?, captionEmpty?, captionSet?, fitLabel? }` — copy of the picture tile
//              (defaults: `Nahrát obrázek`, `Nahrajte obrázek`, `Nahraný obrázek`, `Umístění`)
//
// Three independent layers, stacked colour → gradient → picture: checkbox **Barva** (+ colour, Krytí),
// **Přechod** (+ gradient editor), **Obrázek** (+ upload tile, Vyplnit | Přizpůsobit, Krytí).
// Switching Barva on without a colour starts with white; uploading a picture switches the picture
// layer on; removing the picture leaves the layer on (an empty slot a page can fill).
//
// The element carries `setValue(fill)`; sub-blocks are shown / hidden in place, never rebuilt.

import { checkRow, h } from '../dom.js';
import { sideControl } from './align.js';
import { colorPalette, colorPicker } from './color.js';
import { gradientEditor } from './gradient.js';
import { sliderRow } from './number.js';
import { uploadTile } from './upload.js';

export function fillPanel({ value, onChange, onStart, onCommit, ctx, path, label = 'Pozadí', image = {} }) {
    let current = value;
    const palette = colorPalette(ctx.env);
    const prop = (suffix) => (path ? { 'data-prop': `${path}.${suffix}` } : {});
    const emit = (next, repaint = true) => {
        current = next;
        if (repaint) paint();
        if (onChange) onChange(next);
    };
    const slide = (key) => ({
        onStart,
        onCommit,
        onChange: (v) => emit({ ...current, [key]: v }, false),
    });

    const colorOn = checkRow({ label: 'Barva', checked: current.colorOn, attrs: prop('colorOn'), onChange: (on) => emit({ ...current, colorOn: on, color: current.color || '#FFFFFF' }) });
    const color = colorPicker({ value: current.color || '#FFFFFF', palette, attrs: prop('color'), onChange: (next) => next && emit({ ...current, color: next }) });
    const colorOpacity = sliderRow({ label: 'Krytí', limit: 'percent', unit: '%', value: current.colorOpacity, attrs: prop('colorOpacity'), ...slide('colorOpacity') });
    const colorBlock = h('div', { class: 'cal-bgs' }, color, colorOpacity);

    const gradientOn = checkRow({ label: 'Přechod', checked: current.gradient.on, attrs: prop('gradient.on'), onChange: (on) => emit({ ...current, gradient: { ...current.gradient, on } }) });
    const gradient = gradientEditor({
        value: current.gradient,
        palette,
        path: path ? `${path}.gradient` : null,
        onStart,
        onCommit,
        onChange: (next) => emit({ ...current, gradient: next }, false),
    });
    const gradientBlock = h('div', { class: 'cal-bgs' }, gradient);

    const imageOn = checkRow({ label: 'Obrázek', checked: current.imageOn, attrs: prop('imageOn'), onChange: (on) => emit({ ...current, imageOn: on }) });
    const tile = uploadTile({
        value: current.asset,
        kind: 'image',
        ctx,
        fit: 'cover',
        icon: 'image',
        tip: image.tip || 'Nahrát obrázek',
        captionEmpty: image.captionEmpty || 'Nahrajte obrázek',
        captionSet: image.captionSet || 'Nahraný obrázek',
        attrs: prop('asset'),
        onChange: (id) => emit(id ? { ...current, asset: id, imageOn: true } : { ...current, asset: null }),
    });
    const fit = sideControl({
        label: image.fitLabel || 'Umístění',
        value: current.fit,
        attrs: prop('fit'),
        options: [
            { value: 'cover', label: 'Vyplnit' },
            { value: 'contain', label: 'Přizpůsobit' },
        ],
        onChange: (next) => emit({ ...current, fit: next }),
    });
    const imageOpacity = sliderRow({ label: 'Krytí', limit: 'percent', unit: '%', value: current.imageOpacity, attrs: prop('imageOpacity'), ...slide('imageOpacity') });
    const imageBlock = h('div', { class: 'cal-bgs' }, tile, fit, imageOpacity);

    function paint() {
        colorOn.setChecked(current.colorOn);
        color.setValue(current.color || '#FFFFFF');
        colorOpacity.setValue(current.colorOpacity);
        colorBlock.hidden = !current.colorOn;
        gradientOn.setChecked(current.gradient.on);
        gradient.setValue(current.gradient);
        gradientBlock.hidden = !current.gradient.on;
        imageOn.setChecked(current.imageOn);
        tile.setValue(current.asset);
        fit.setValue(current.fit);
        imageOpacity.setValue(current.imageOpacity);
        imageBlock.hidden = !current.imageOn;
    }

    const el = h('div', { class: 'cal-psec cal-bgp' }, label ? h('span', { class: 'lbl' }, label) : null, colorOn, colorBlock, gradientOn, gradientBlock, imageOn, imageBlock);
    paint();
    el.setValue = (next) => {
        if (next === current) return;
        current = next;
        paint();
    };
    return el;
}
