/*
 * overflow-wrap: break-word for Fabric Textbox.
 *
 * Fabric's Textbox only wraps on whitespace (`_wordJoiners`), so a single long
 * word / unbroken string is never split and overflows the box width. Worse,
 * its wrapper raises the per-line threshold to the widest word
 * (`Math.max(desiredWidth, largestWordWidth)` in _wrapLine), so ONE long word
 * widens the whole text block. We wrap `getGraphemeDataForRender` (the
 * word+width builder that feeds _wrapLine): any word wider than the box is
 * greedily sliced into grapheme chunks that each fit, so normal text still
 * wraps on spaces while an over-long word hard-breaks to respect the width.
 * Chunks are packed ~full-width, so _wrapLine always wraps between them and
 * never re-inserts an infix space (which would corrupt the text). Grapheme
 * mode already breaks everywhere, so it's left untouched.
 *
 * Classic script on purpose (no ES module syntax): it is inlined verbatim into
 * the headless Gotenberg render template AND loaded via <script src> by the
 * admin editor and the fill page, so all three surfaces wrap text identically
 * — text measurement parity is what container reflow correctness rests on.
 * Call `WBoostFabricBreakWord.enable(Textbox)` once per page with whichever
 * Textbox class that surface uses (UMD global or ESM import — same prototype).
 */
(function (global) {
    'use strict';

    function enable(Textbox) {
        if (!Textbox || typeof Textbox.prototype.getGraphemeDataForRender !== 'function') {
            return;
        }
        if (Textbox.prototype.__wboostBreakWordEnabled) {
            return;
        }
        Textbox.prototype.__wboostBreakWordEnabled = true;

        const proto = Textbox.prototype;
        const original = proto.getGraphemeDataForRender;
        proto.getGraphemeDataForRender = function (lines) {
            const data = original.call(this, lines);
            if (this.splitByGrapheme || !(this.width > 0)) {
                return data;
            }
            const limit = this.width;
            let largest = 0;
            const wordsData = data.wordsData.map((lineWords, lineIndex) => {
                const out = [];
                let offset = 0;
                for (const entry of lineWords) {
                    const { word, width } = entry;
                    // Fits the box, or a single grapheme that can't be
                    // broken further — keep as-is.
                    if (width <= limit || word.length <= 1) {
                        out.push(entry);
                        largest = Math.max(largest, width);
                        offset += word.length + 1;
                        continue;
                    }
                    // Greedily fill grapheme chunks up to the box width.
                    let chunk = [];
                    let chunkWidth = 0;
                    for (let g = 0; g < word.length; g++) {
                        const candidate = chunk.concat(word[g]);
                        const candidateWidth = this._measureWord(candidate, lineIndex, offset);
                        if (chunk.length > 0 && candidateWidth > limit) {
                            out.push({ word: chunk, width: chunkWidth });
                            largest = Math.max(largest, chunkWidth);
                            chunk = [word[g]];
                            chunkWidth = this._measureWord(chunk, lineIndex, offset);
                        } else {
                            chunk = candidate;
                            chunkWidth = candidateWidth;
                        }
                    }
                    if (chunk.length > 0) {
                        out.push({ word: chunk, width: chunkWidth });
                        largest = Math.max(largest, chunkWidth);
                    }
                    offset += word.length + 1;
                }
                return out;
            });
            return { wordsData, largestWordWidth: largest };
        };
    }

    global.WBoostFabricBreakWord = { enable };
})(typeof window !== 'undefined' ? window : globalThis);
