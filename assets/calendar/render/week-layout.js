// Geometry of a week page (architecture §E.6). Pure: the renderer serialises what this computes and
// the editor overlay positions its chrome from the same object.

import { K, ptToMm } from '../model/defaults.js';
import { visibleBoxKeys } from '../model/elements.js';
import { flipRowStyles } from '../model/layouts.js';
import { createDayContext, dayInfo } from '../generate/day.js';
import { fontSpec } from './svg.js';
import { wrapText } from './measure.js';

const NUMBER_KINDS = ['num', 'numSat', 'numSun', 'numHol'];
export const MONTH_LETTER_SPACING = 0.09;

/** Month text as drawn: upper case, or lower case with the first letter of each month capitalised. */
export function monthLabel(text, upper) {
    if (upper) return String(text).toUpperCase();
    return String(text)
        .toLowerCase()
        .replace(/(^|\/ )(\p{L})/gu, (m, lead, ch) => lead + ch.toUpperCase());
}

export function fillIsOn(fill) {
    return Boolean(fill && ((fill.colorOn && fill.color) || (fill.gradient && fill.gradient.on) || fill.imageOn));
}

/** FontSpec + text of the Měsíc element for a page. */
export function monthTextSpec(styles, fonts, text) {
    const s = styles.month;
    return { text: monthLabel(text, s.upper), font: fontSpec(s, fonts, ptToMm(s.size), s.upper ? MONTH_LETTER_SPACING : 0), pad: fillIsOn(s.bg) ? s.pad : 0 };
}

function union(a, b) {
    if (!a) return { ...b };
    const x = Math.min(a.x, b.x);
    const y = Math.min(a.y, b.y);
    return { x, y, w: Math.max(a.x + a.w, b.x + b.w) - x, h: Math.max(a.y + a.h, b.y + b.h) - y };
}

const anchorX = (col, anchor) => (anchor === 'start' ? col.x : anchor === 'end' ? col.x + col.w : col.x + col.w / 2);
const opposite = (anchor) => (anchor === 'start' ? 'end' : anchor === 'end' ? 'start' : anchor);

function gridCells(grid, cellW, Hc) {
    // flow-relative cells: { u, w, y, h, double }
    switch (grid) {
        case '4':
            return [0, 1, 2, 3].map((i) => ({ u: i * cellW, w: cellW, y: 0, h: Hc, double: false }));
        case '2x2':
            return [0, 1, 2, 3].map((i) => ({ u: (i % 2) * cellW, w: cellW, y: i < 2 ? 0 : Hc / 2, h: Hc / 2, double: false }));
        case '2+1':
            return [
                { u: 0, w: cellW, y: 0, h: Hc * 0.45, double: false },
                { u: cellW, w: cellW, y: 0, h: Hc * 0.45, double: false },
                { u: 0, w: 2 * cellW, y: Hc * 0.45, h: Hc * 0.55, double: true },
            ];
        case '1+2':
            return [
                { u: 0, w: 2 * cellW, y: 0, h: Hc * 0.55, double: true },
                { u: 0, w: cellW, y: Hc * 0.55, h: Hc * 0.45, double: false },
                { u: cellW, w: cellW, y: Hc * 0.55, h: Hc * 0.45, double: false },
            ];
        default:
            return [];
    }
}

function layoutFrame(c, f) {
    const { doc, page, measurer, fonts, dayContext: dc } = c;
    const design = doc.design;
    const features = design.features;
    const layout = design.layout;
    const mirrored = f === 1;
    const boxKey = mirrored ? 'cal2' : 'cal';
    const box = layout.boxes[boxKey];
    const S = mirrored ? flipRowStyles(layout.styles) : layout.styles;
    const days = page.week.frames[f] || [];
    const W = design.page.width;
    const H = design.page.height;

    // E.6.1 frame and rows
    const x0 = Math.max(box.x, 0);
    const y0 = Math.max(box.y, 0);
    const inner = {
        x: x0,
        y: y0,
        w: Math.max(K.MIN_W, Math.min(box.x + box.w, W) - x0),
        h: Math.max(K.MIN_H, Math.min(box.y + box.h, H) - y0),
    };
    const n = Math.max(1, days.length);
    const rh = (inner.h - K.ROW_SLACK) / n;
    const compact = rh < K.COMPACT_BELOW;
    const sc = compact ? Math.min(1, rh / K.COMPACT_BASE) : 1;
    const sz = (k) => ptToMm(S[k].size) * sc;
    const font = (k, size) => fontSpec(S[k], fonts, size === undefined ? sz(k) : size);
    const width = (text, spec) => measurer.width(text, spec);

    // E.6.2 flow axis
    const side = S.cal.numberSide;
    const zoneLeft = side === 'end' || (side === 'middle' && !mirrored);
    const w = inner.w;
    const X = (u) => (zoneLeft ? inner.x + u : inner.x + w - u);
    const span = (a, b) => ({ x: Math.min(X(a), X(b)), w: Math.max(0, b - a) });

    const infos = days.map((d) => dayInfo(doc, d, dc));
    const dayLabel = (info) => (S.cal.dayFormat === 'short' ? info.weekdayShort : info.weekday);

    // E.6.3 number column
    const nCap = rh * (compact ? 0.78 : 0.74);
    const nSz = (k) => Math.min(ptToMm(S[k].size), nCap);
    const nMax = Math.min(Math.max(...NUMBER_KINDS.map((k) => ptToMm(S[k].size))), nCap);
    const above = S.cal.dayPosition === 'above' && !compact;
    let numW = nMax * 1.3;
    if (above) {
        const dayFont = font('day');
        for (const info of infos) numW = Math.max(numW, width(dayLabel(info), dayFont));
    }
    const numFlow = side !== 'middle' ? [w - K.ROW_PAD - numW, w - K.ROW_PAD] : [w / 2 - numW / 2, w / 2 + numW / 2];
    const numCol = span(numFlow[0], numFlow[1]);
    const numAlign = S.cal.numAlign || side;

    // E.6.4 zone
    const ops = features.shifts ? dc.shiftOps : [];
    const shiftStep = K.SHIFT_COL_W + K.SHIFT_COL_GAP;
    const shW = ops.length && !compact && rh >= K.SHIFT_MIN_ROW ? ops.length * shiftStep + K.SHIFT_END_GAP : 0;
    let zone0 = shW > 0 ? shW : K.ROW_PAD;
    const shiftLine = (info) => info.shifts.map((col) => col.join(' ')).join('  |  ');
    if (ops.length && shW === 0) {
        // compact rows print the shifts as one text; the icons start after the widest one
        const shiftFont = font('shift');
        let widest = 0;
        for (const info of infos) widest = Math.max(widest, width(shiftLine(info), shiftFont));
        zone0 += widest + K.SHIFT_END_GAP;
    }
    const keys = dc.iconKeys;
    const cellW = Math.max(S.cal.iconCellW || 0, S.cal.iconSize + K.ICON_GAP);
    const grid = compact ? null : S.cal.iconGrid;
    const cols = grid === '4' ? 4 : grid ? 2 : keys.length;
    const emptySlot = keys.length === 0 && S.cal.emptyIconSlot;
    let iconsW = keys.length ? cols * cellW : emptySlot ? K.EMPTY_SLOT_W : 0;
    let labelsW = 0;
    const placeFont = font('placeText');
    if (S.cal.placeLabels && features.waste) {
        for (const [, kind] of dc.wasteKinds) {
            const place = kind.on ? (kind.place || '').trim() : '';
            if (place) labelsW = Math.max(labelsW, width(place, placeFont));
        }
        if (labelsW > 0) labelsW += 2.5;
    }
    if (!grid && keys.length && side !== 'middle') {
        // row mode reserves a slot per active icon key; when that would squeeze the text column under
        // COMPACT_TEXT_MIN, only the slots the fullest day of this frame needs are kept
        let most = 0;
        for (const info of infos) most = Math.max(most, info.icons.length);
        const room = w - K.ROW_PAD - numW - K.NUM_GAP - zone0 - labelsW - K.ZONE_END_GAP - K.COMPACT_TEXT_MIN;
        iconsW = Math.max(most * cellW, Math.min(iconsW, room));
    }
    const zoneEnd = zone0 + iconsW + labelsW + (iconsW > 0 ? K.ZONE_END_GAP : 0);

    const textFlow = side !== 'middle' ? [zoneEnd, w - K.ROW_PAD - numW - K.NUM_GAP] : [w / 2 + numW / 2 + K.NUM_GAP, w - K.ROW_PAD];
    // a frame too narrow for its zone: the text keeps its place beside the number
    if (textFlow[1] < textFlow[0] + 1) textFlow[0] = textFlow[1] - 1;
    const textCol = span(textFlow[0], textFlow[1]);
    const colW = textCol.w;
    // towards the number: right-aligned when the text column lies left of the numbers
    const textAnchor = textCol.x + textCol.w / 2 < numCol.x + numCol.w / 2 ? 'end' : 'start';

    // E.6.5 text blocks of the normal mode, measured up front: the default event box ends at the widest line
    const eventsOn = Boolean(features.events);
    const defaultEvBox = eventsOn && !compact && !S.ev.box;
    const dS = sz('day');
    const nmS = sz('name');
    let blocks = null;
    let holWrapW = Math.min(S.hol.wrapWidth || colW, colW);
    let textWidest = 0;
    let namesWidest = 0;
    if (!compact) {
        const dayFont = font('day');
        const nameFont = font('name');
        const holFont = font('hol');
        const timeFont = font('time');
        const widest = (list) => list.reduce((m, line) => Math.max(m, width(line.text, line.font)), 0);
        blocks = infos.map((info) => {
            const dayColor = info.holiday || info.wd === 6 ? S.cal.sunColor : info.wd === 5 ? S.cal.satColor : S.day.color;
            const lines = [];
            if (!above) lines.push({ part: 'day', text: dayLabel(info), font: dayFont, fill: dayColor });
            const nameLines = wrapText(info.names, colW, nameFont, measurer, { maxLines: 2 });
            for (const line of nameLines) lines.push({ part: 'name', text: line, font: nameFont, fill: S.name.color });
            const own = widest(lines);
            namesWidest = Math.max(namesWidest, own);
            return { lines, nameLines: nameLines.length, widest: own };
        });
        // beside a default event box a long holiday wraps at half the column, so the events keep their room
        // — but never so narrow that a wrapped holiday would leave its row
        if (defaultEvBox && !S.hol.wrapWidth) {
            holWrapW = Math.min(colW, Math.max(colW / 2, ...blocks.map((b) => widest(b.lines))));
            const fits = (wrapW) => blocks.every((block, i) => {
                const used = block.lines.reduce((sum, line) => sum + 1.22 * line.font.sizeMm, 0);
                return used + wrapText(infos[i].holiday, wrapW, holFont, measurer).length * 1.22 * holFont.sizeMm <= rh - 0.4;
            });
            while (holWrapW < colW && !fits(holWrapW)) holWrapW = Math.min(colW, holWrapW + 2);
        }
        blocks.forEach((block, i) => {
            const info = infos[i];
            for (const line of wrapText(info.holiday, holWrapW, holFont, measurer)) block.lines.push({ part: 'hol', text: line, font: holFont, fill: S.hol.color });
            const timeText = info.timeTexts.join(' · ');
            if (timeText && S.time.position === 'text') block.lines.push({ part: 'time', text: timeText, font: timeFont, fill: S.time.color });
            block.widest = widest(block.lines);
            textWidest = Math.max(textWidest, block.widest);
        });
    }
    // the default event box: one page-wide box beside the weekday / name-day lines (both frames of a page
    // share the wider value, so they mirror exactly); the rare row whose holiday or time text is wider
    // gets a narrower box of its own instead of narrowing every row of the page
    const evNamesW = Math.min(colW, Math.max(namesWidest, c.evNamesW || 0));
    const evPageW = Math.max(K.EVBOX_MIN_W, colW - evNamesW - K.EVBOX_GAP);
    const evDefault = defaultEvBox ? { x: side === 'middle' ? colW - evPageW : 0, w: evPageW } : null;
    const holWrap = { x: textAnchor === 'end' ? textCol.x + textCol.w - holWrapW : textCol.x, w: holWrapW };

    const offsets = S.offsets || {};
    const offsetOf = (part) => offsets[part] || ((part === 'waste' || part === 'moon') && offsets.icon) || { x: 0, y: 0 };

    const Hc = rh - 1.2;
    const cells = grid ? gridCells(grid, cellW, Hc) : [];
    const cellConf = (grid && S.cal.iconCells && S.cal.iconCells[grid]) || {};

    const rows = infos.map((info, i) => {
        const t = inner.y + i * rh;
        const items = [];
        const add = (item) => {
            const off = offsetOf(item.part);
            if (off.x || off.y) {
                if (item.type === 'text') {
                    item.x += off.x;
                    item.y += off.y;
                } else if (item.type === 'line') {
                    item.x1 += off.x;
                    item.x2 += off.x;
                    item.y += off.y;
                } else {
                    item.box = { ...item.box, x: item.box.x + off.x, y: item.box.y + off.y };
                }
                item.rect = { ...item.rect, x: item.rect.x + off.x, y: item.rect.y + off.y };
            }
            items.push(item);
            return item;
        };
        const text = (part, x, y, value, spec, anchor, fill) => {
            if (value === '' || value === null || value === undefined) return null;
            const tw = width(value, spec);
            const left = anchor === 'start' ? x : anchor === 'end' ? x - tw : x - tw / 2;
            return add({ part, type: 'text', x, y, text: value, font: spec, anchor, fill, rect: { x: left, y: y - 0.78 * spec.sizeMm, w: tw, h: spec.sizeMm } });
        };

        // shifts
        if (shW > 0) {
            const sS = sz('shift');
            ops.forEach((op, ci) => {
                const col = span(ci * shiftStep, ci * shiftStep + K.SHIFT_COL_W);
                const colBox = { x: col.x, y: t, w: col.w, h: rh };
                const mode = S.shift.bgMode;
                if (mode === 'all' || (mode === 'alt' && ci % 2 === 1)) {
                    add({ part: 'shift', type: 'rect', box: colBox, fill: S.shift.bg || S.shift.color, opacity: S.shift.bg ? 1 : 0.14, rect: colBox });
                }
                const crews = info.shifts[ci] || [];
                const count = Math.max(1, crews.length);
                const lh = Math.min(1.15 * sS, (rh - 2) / count);
                const fs = Math.min(sS, 0.95 * lh);
                const top = t + (rh - crews.length * lh) / 2;
                crews.forEach((crew, j) => text('shift', col.x + col.w / 2, top + j * lh + lh / 2 + 0.36 * fs, crew, font('shift', fs), 'middle', S.shift.color));
            });
        } else if (ops.length) {
            const spec = font('shift');
            text('shift', X(K.ROW_PAD), t + rh / 2 + 0.36 * spec.sizeMm, shiftLine(info), spec, zoneLeft ? 'start' : 'end', S.shift.color);
        }

        // line under the row
        if (S.lines.on) {
            const lw = ptToMm(S.lines.width);
            add({
                part: 'line',
                type: 'line',
                x1: inner.x + K.ROW_PAD,
                x2: inner.x + w - K.ROW_PAD,
                y: t + rh,
                width: lw,
                style: S.lines.style,
                color: S.lines.color,
                rect: { x: inner.x + K.ROW_PAD, y: t + rh - Math.max(lw, 0.4) / 2, w: Math.max(0, w - 2 * K.ROW_PAD), h: Math.max(lw, 0.4) },
            });
        }

        // icons
        const iconOff = offsetOf('icon');
        const iconCells = cells.map((cell) => {
            const s = span(zone0 + cell.u, zone0 + cell.u + cell.w);
            return { x: s.x + iconOff.x, y: t + 0.6 + cell.y + iconOff.y, w: cell.w, h: cell.h };
        });
        let iconsDrawn = 0;
        const iconsTotal = info.icons.length;
        const partOfIcon = (ref) => (ref.category === 'waste' ? 'waste' : ref.category === 'moon' ? 'moon' : 'icon');
        if (grid) {
            info.icons.slice(0, cells.length).forEach((ref, ci) => {
                const cell = cells[ci];
                const s = span(zone0 + cell.u, zone0 + cell.u + cell.w);
                const cellBox = { x: s.x, y: t + 0.6 + cell.y, w: cell.w, h: cell.h };
                const conf = cellConf[ci] || {};
                let iw;
                let ih;
                let valign = 'middle';
                if (ref.category === 'moon') {
                    iw = ih = Math.max(0.6, Math.min(S.moon.size, 0.8 * rh, cellBox.w - 0.3, cellBox.h - 0.3));
                    valign = S.moon.valign;
                } else {
                    const wanted = conf.size || (cell.double ? Infinity : S.cal.iconSize);
                    iw = Math.max(0.6, Math.min(cellBox.w - 0.3, (cellBox.h - 0.3) / 1.15, wanted));
                    ih = ref.category === 'waste' || ref.category === 'ev' ? iw * 1.15 : iw;
                }
                const align = conf.align || 'middle';
                const ix = align === 'start' ? cellBox.x + 0.15 : align === 'end' ? cellBox.x + cellBox.w - 0.15 - iw : cellBox.x + (cellBox.w - iw) / 2;
                const iy = valign === 'top' ? cellBox.y + 0.15 : valign === 'bottom' ? cellBox.y + cellBox.h - 0.15 - ih : cellBox.y + (cellBox.h - ih) / 2;
                const iconBox = { x: ix, y: iy, w: iw, h: ih };
                add({ part: partOfIcon(ref), type: 'icon', box: iconBox, ref, rect: iconBox });
                iconsDrawn++;
            });
        } else {
            let u = zone0;
            for (const ref of info.icons) {
                const isMoon = ref.category === 'moon';
                const moonD = Math.min(S.moon.size, 0.8 * rh);
                const slot = isMoon ? Math.max(cellW, moonD + K.ICON_GAP) : cellW;
                const s = span(u, u + slot);
                const tall = ref.category === 'waste' || ref.category === 'ev';
                const ih = isMoon ? moonD : Math.min((tall ? 1.15 : 1) * S.cal.iconSize, 0.72 * rh);
                const iw = isMoon ? moonD : Math.min(S.cal.iconSize, tall ? ih / 1.15 : ih);
                const valign = isMoon ? S.moon.valign : 'middle';
                const iy = valign === 'top' ? t + 0.6 : valign === 'bottom' ? t + rh - 0.6 - ih : t + (rh - ih) / 2;
                const iconBox = { x: s.x + (slot - iw) / 2, y: iy, w: iw, h: ih };
                add({ part: partOfIcon(ref), type: 'icon', box: iconBox, ref, rect: iconBox });
                iconsDrawn++;
                u += slot;
            }
        }
        if (emptySlot) {
            const s = span(zone0, zone0 + K.EMPTY_SLOT);
            const sh = Math.min(K.EMPTY_SLOT, 0.62 * rh);
            const slotBox = { x: s.x, y: t + (rh - sh) / 2, w: K.EMPTY_SLOT, h: sh };
            add({ part: 'icon', type: 'slot', box: slotBox, rect: slotBox });
        }
        if (labelsW > 0 && info.places.length) {
            text('icon', X(zone0 + iconsW + 1), t + rh / 2 + 0.36 * placeFont.sizeMm, info.places.join(' · '), placeFont, zoneLeft ? 'start' : 'end', S.placeText.color);
        }

        // texts
        const dayColor = info.holiday || info.wd === 6 ? S.cal.sunColor : info.wd === 5 ? S.cal.satColor : S.day.color;
        const tx = anchorX(textCol, textAnchor);
        let eventBox = null;
        let eventOverflow = false;
        const timeText = info.timeTexts.join(' · ');

        if (compact) {
            // E.6.7 one baseline, flowing from the number outwards
            const dir = textAnchor === 'end' ? -1 : 1;
            const dayFont = font('day');
            const nameFont = font('name');
            const bl = t + rh / 2 + 0.36 * dayFont.sizeMm;
            let cursor = tx;
            const label = dayLabel(info);
            text('day', cursor, bl, label, dayFont, textAnchor, dayColor);
            cursor += dir * (width(label, dayFont) + 2.5);
            const remainingAt = (at) => Math.max(0, dir < 0 ? at - textCol.x : textCol.x + textCol.w - at);
            const afterNames = info.names ? cursor + dir * (width(info.names, nameFont) + 3) : cursor;
            const holFont = font('hol');
            const holLh = 1.15 * holFont.sizeMm;
            const holMax = Math.max(1, Math.floor((rh - 0.6) / holLh));
            const holAt = (at) => wrapText(info.holiday, Math.max(remainingAt(at), K.HOL_WRAP_MIN), holFont, measurer);
            let holLines = info.holiday ? holAt(afterNames) : [];
            let showNames = Boolean(info.names);
            if (holLines.length > holMax && showNames) {
                // the holiday does not fit beside the name day in this row: the name day gives way
                showNames = false;
                holLines = holAt(cursor);
            }
            if (holLines.length > holMax) {
                // still too tall: the narrowest width that fits the row height (it may reach into the zone — never cut)
                let wrapW = Math.max(remainingAt(cursor), K.HOL_WRAP_MIN);
                const full = width(info.holiday, holFont);
                while (holLines.length > holMax && wrapW < full) {
                    wrapW += 1;
                    holLines = wrapText(info.holiday, wrapW, holFont, measurer);
                }
                if (holLines.length > holMax) holLines = [info.holiday];
            }
            if (showNames) {
                text('name', cursor, bl, info.names, nameFont, textAnchor, S.name.color);
                cursor = afterNames;
            }
            const remaining = remainingAt(cursor);
            const joinedEvents = info.events.map((e) => e.text).join(' · ');
            if (info.holiday) {
                const top = t + (rh - holLines.length * holLh) / 2;
                holLines.forEach((line, j) => text('hol', cursor, top + j * holLh + holLh / 2 + 0.36 * holFont.sizeMm, line, holFont, textAnchor, S.hol.color));
            } else if (eventsOn && joinedEvents) {
                const evFont = font('ev');
                text('ev', cursor, t + rh / 2 + 0.36 * evFont.sizeMm, joinedEvents, evFont, textAnchor, S.ev.color);
                eventOverflow = width(joinedEvents, evFont) > remaining + 0.15;
            } else if (timeText) {
                const timeFont = font('time');
                text('time', cursor, t + rh / 2 + 0.36 * timeFont.sizeMm, timeText, timeFont, textAnchor, S.time.color);
            }
            if (eventsOn && info.eventsDropped > 0) eventOverflow = true;
        } else {
            // E.6.5 stacked text block
            const timeFont = font('time');
            const { lines, nameLines } = blocks[i];
            const base = 1.22 * ((above ? 0 : dS) + nmS * Math.max(1, nameLines));
            let total = 0;
            for (const line of lines) total += 1.22 * line.font.sizeMm;
            let y = t + (rh - base) / 2;
            if (y + total > t + rh - 0.5) y = Math.max(t + 0.5, t + (rh - total) / 2);
            for (const line of lines) {
                y += line.font.sizeMm;
                text(line.part, tx, y, line.text, line.font, textAnchor, line.fill);
                y += 0.22 * line.font.sizeMm;
            }

            // E.6.6 event box
            if (eventsOn) {
                const evFont = font('ev');
                const eS = evFont.sizeMm;
                const stored = S.ev.box;
                let bx;
                let by;
                let bw;
                let bh;
                if (stored) {
                    bx = stored.x;
                    by = stored.y;
                    bw = Math.max(K.EVBOX_MIN_W, stored.w);
                    bh = Math.max(K.EVBOX_MIN_H, stored.h);
                } else {
                    // default: the free part of the text column beside the day text, the whole row height
                    const free = textFlow[1] - textFlow[0] - Math.min(colW, blocks[i].widest) - K.EVBOX_GAP;
                    bw = Math.max(K.EVBOX_MIN_W, Math.min(evPageW, free));
                    bx = side === 'middle' ? textFlow[1] - textFlow[0] - bw : 0;
                    by = K.EVBOX_PAD_Y;
                    bh = Math.max(K.EVBOX_MIN_H, rh - 2 * K.EVBOX_PAD_Y);
                }
                const s = span(textFlow[0] + bx, textFlow[0] + bx + bw);
                const off = offsetOf('ev');
                const raw = { x: s.x, y: t + by, w: bw, h: bh };
                eventBox = { x: raw.x + off.x, y: raw.y + off.y, w: bw, h: bh };
                const evLines = [];
                for (const event of info.events) evLines.push(...wrapText(event.text, bw, evFont, measurer));
                const lh = 1.2 * eS;
                const blockH = evLines.length * lh;
                const align = S.ev.align || opposite(textAnchor);
                const ex = anchorX(raw, align);
                // a block taller than its box starts at the top, so it never prints over the row above
                const top = blockH > bh ? raw.y : S.ev.valign === 'middle' ? raw.y + (bh - blockH) / 2 : S.ev.valign === 'bottom' ? raw.y + bh - blockH : raw.y;
                evLines.forEach((line, j) => text('ev', ex, top + j * lh + 0.86 * eS, line, evFont, align, S.ev.color));
                eventOverflow = evLines.length > 0 && blockH > bh + 0.15;
                if (info.eventsDropped > 0) eventOverflow = true;
            }

            // separate time text
            if (timeText && S.time.position === 'sep') {
                const size = Math.min(timeFont.sizeMm, 0.4 * rh);
                const align = S.time.align || opposite(textAnchor);
                const ty = S.time.valign === 'top' ? t + 1 + size : S.time.valign === 'middle' ? t + rh / 2 + 0.36 * size : t + rh - 1 - 0.2 * size;
                text('time', anchorX(textCol, align), ty, timeText, font('time', size), align, S.time.color);
            }
        }

        // number (and the weekday above it)
        const kind = info.numberKind;
        const nz = nSz(kind);
        let numBase = t + rh / 2 + 0.36 * nz;
        if (above) {
            const dayFont = font('day');
            const dS = dayFont.sizeMm;
            const blockH = 1.2 * dS + 0.74 * nz;
            const top = t + (rh - blockH) / 2;
            const dayAlign = S.cal.dayAlign || numAlign;
            text('day', anchorX(numCol, dayAlign), top + 0.85 * dS, dayLabel(info), dayFont, dayAlign, dayColor);
            numBase = top + blockH;
        }
        text(kind, anchorX(numCol, numAlign), numBase, String(info.d), font(kind, nz), numAlign, S[kind].color);

        const order = [];
        const rects = {};
        for (const item of items) {
            if (!rects[item.part]) order.push(item.part);
            rects[item.part] = union(rects[item.part], item.rect);
        }
        return {
            date: info.date,
            y: t,
            h: rh,
            parts: order.map((key) => ({ key, rect: rects[key] })),
            eventBox,
            eventOverflow,
            iconCells,
            iconsDrawn,
            iconsTotal,
            items,
            info,
        };
    });

    const zoneSpan = span(zone0, zone0 + iconsW);
    const iconOffset = offsetOf('icon');
    return {
        index: f,
        boxKey,
        box: { x: box.x, y: box.y, w: box.w, h: box.h },
        inner,
        mirrored,
        zoneLeft,
        rowHeight: rh,
        compact,
        textColumn: { x: textCol.x, w: textCol.w },
        textAnchor,
        holWrap,
        iconZone: keys.length ? { x: zoneSpan.x + iconOffset.x, w: iconsW, cols, cellW } : null,
        numberColumn: { x: numCol.x, w: numCol.w },
        evOffset: { ...offsetOf('ev') },
        textWidest,
        namesWidest,
        evDefault,
        styles: S,
        rows,
    };
}

/**
 * @param doc    Doc with a layout
 * @param page   a week page of `buildPages(doc)`
 * @param opts   LayoutOpts `{ env: { assets, fonts }, measurer, dayContext? }`
 * @returns WeekGeometry (plus renderer-internal fields: `frames[].styles`, `frames[].rows[].items`, `.info`)
 */
export function layoutWeekPage(doc, page, opts) {
    const design = doc.design;
    const layout = design.layout;
    const fonts = (opts.env && opts.env.fonts) || [];
    const measurer = opts.measurer;
    const c = { doc, page, measurer, fonts, dayContext: opts.dayContext || createDayContext(doc) };

    const boxes = {};
    for (const key of visibleBoxKeys(design)) {
        const b = layout.boxes[key];
        boxes[key] = { x: b.x, y: b.y, w: b.w, h: b.h };
    }
    const build = () => {
        const list = [];
        for (let f = 0; f < page.week.frames.length; f++) {
            if (layout.boxes[f === 1 ? 'cal2' : 'cal']) list.push(layoutFrame(c, f));
        }
        return list;
    };
    let frames = build();
    if (frames.length > 1 && design.features.events && !layout.styles.ev.box) {
        // both frames of a 14-day page get the same default event box
        const widest = Math.max(...frames.map((f) => f.namesWidest));
        if (frames.some((f) => f.namesWidest < widest - 0.01)) {
            c.evNamesW = widest;
            frames = build();
        }
    }

    const month = monthTextSpec(layout.styles, fonts, page.week.monthText);
    const wkFont = fontSpec(layout.styles.wk, fonts);
    return {
        boxes,
        frames,
        textWidths: {
            month: measurer.width(month.text, month.font) + 2 * month.pad,
            wk: measurer.width(page.week.weekText, wkFont),
        },
    };
}
