import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Caps, limitsFromDefinition, DEFAULT_LIMITS } from '../src/caps.mjs';

test('limits come from the definition, with the architecture defaults behind them', () => {
    assert.deepEqual(limitsFromDefinition({}), DEFAULT_LIMITS);
    const l = limitsFromDefinition({ caps: { turns: 12, engineBudgetMicroUsd: 500_000, candidates: 2, validationRejections: 1, images: 5 }, timing: { builderCapSeconds: 60, runCapSeconds: 90 } });
    assert.deepEqual(l, { turns: 12, budgetUsd: 0.5, builderMs: 60_000, runMs: 90_000, candidates: 2, rejections: 1, images: 5 });
});

test('at most 3 candidates, 3 rejections, 18 images', () => {
    const c = new Caps();
    assert.equal([1, 2, 3, 4].map(() => c.takeCandidate()).join(), 'true,true,true,false');
    assert.equal(c.candidatesLeft(), 0);
    c.takeRejection(); c.takeRejection();
    assert.equal(c.rejectionsLeft(), 1);
    assert.equal(c.takeRejection(), false);
    assert.equal(c.takeImages(17), 17);
    assert.equal(c.takeImages(5), 1, 'only what is left is granted');
    assert.equal(c.takeImages(1), 0);
    assert.equal(c.imagesLeft(), 0);
});

test('exceeded() names the first cap that is used up', () => {
    let now = 0;
    const c = new Caps({ turns: 5, budgetUsd: 1, builderMs: 300, runMs: 360 }, () => now);
    assert.equal(c.exceeded(), null);
    c.noteTurns(5);
    assert.equal(c.exceeded(), 'turns');
    c.turns = 0; c.noteCost(1.2);
    assert.equal(c.exceeded(), 'budget');
    c.costUsd = 0; c.startBuilder(); now = 301;
    assert.equal(c.exceeded(), 'builder_time');
    now = 361;
    assert.equal(c.exceeded(), 'run_time', 'the run cap outranks the builder cap');
});

test('turn and cost counters never go backwards', () => {
    const c = new Caps();
    c.noteTurns(7); c.noteTurns(3); c.noteCost(0.4); c.noteCost(0.1); c.noteCost(NaN);
    assert.equal(c.turns, 7);
    assert.equal(c.costUsd, 0.4);
});

test('a cap hit is the provider\'s fault when half of the builder time was lost to it', () => {
    let now = 0;
    const c = new Caps({}, () => now);
    c.startBuilder(); now = 100_000;
    c.noteProviderLost(40_000);
    assert.equal(c.providerLostDominates(0.5), false);
    c.noteProviderLost(15_000);
    assert.equal(c.providerLostDominates(0.5), true);
});
