import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classifyProviderError, outcomeForClass } from '../src/engine/classify.mjs';

// One row per signal of architecture §7 (and E26): [input, class, code]
const TABLE = [
    // transient
    [{ status: 429, type: 'rate_limit_error' }, 'transient', 'rate_limit'],
    [{ status: 429, type: 'rate_limit_error', message: 'no retry-after header at all' }, 'transient', 'rate_limit'],
    [{ status: 500, type: 'api_error' }, 'transient', 'server_error'],
    [{ status: 504 }, 'transient', 'server_error'],
    [{ status: 529, type: 'overloaded_error' }, 'transient', 'overloaded'],
    [{ status: null, network: true }, 'transient', 'network'],
    [{ midStream: true }, 'transient', 'stream_interrupted'],
    [{ sdkError: 'rate_limit' }, 'transient', 'rate_limit'],
    [{ sdkError: 'overloaded' }, 'transient', 'overloaded'],
    [{ sdkError: 'server_error' }, 'transient', 'server_error'],
    // billing / auth
    [{ status: 402, type: 'billing_error' }, 'billing_auth', 'billing'],
    [{ status: 401, type: 'authentication_error' }, 'billing_auth', 'authentication'],
    [{ status: 403, type: 'permission_error' }, 'billing_auth', 'permission'],
    [{ status: 429, type: 'rate_limit_error', errorCode: 'enforced_spend_limit_reached' }, 'billing_auth', 'spend_limit'],
    [{ status: 400, type: 'invalid_request_error', message: 'You have reached your specified workspace API usage limits.' }, 'billing_auth', 'usage_limit'],
    [{ sdkError: 'billing_error' }, 'billing_auth', 'billing'],
    [{ sdkError: 'authentication_failed' }, 'billing_auth', 'authentication'],
    [{ sdkError: 'account_on_hold' }, 'billing_auth', 'account_on_hold'],
    [{ sdkError: 'rate_limit', planLimit: true }, 'billing_auth', 'plan_limit'],
    // what the Agent SDK really hands over (eval/engine-facts.mjs): status + its own error word + the message text
    [{ status: 429, sdkError: 'rate_limit', message: 'API Error: Request rejected (429) · spend limit reached' }, 'billing_auth', 'spend_limit'],
    [{ status: 400, sdkError: 'unknown', message: 'API Error: 400 You have reached your specified workspace API usage limits. You will regain access on 2026-12-01 at 00:00 UTC.' }, 'billing_auth', 'usage_limit'],
    [{ status: 402, sdkError: 'unknown', message: 'API Error: 402 billing_error' }, 'billing_auth', 'billing'],
    [{ status: 403, sdkError: 'authentication_failed' }, 'billing_auth', 'permission'],
    [{ status: 529, sdkError: 'server_error', message: 'API Error: Repeated 529 Overloaded errors.' }, 'transient', 'overloaded'],
    [{ status: 400, sdkError: 'invalid_request', message: 'Image was too large.' }, 'request', 'invalid_request'],
    // request
    [{ status: 400, type: 'invalid_request_error', message: 'messages.0.content: image exceeds 8000 px' }, 'request', 'invalid_request'],
    [{ status: 404, type: 'not_found_error' }, 'request', 'model_not_found'],
    [{ status: 413, type: 'request_too_large' }, 'request', 'request_too_large'],
    [{ sdkError: 'invalid_request' }, 'request', 'invalid_request'],
    [{ sdkError: 'model_not_found' }, 'request', 'model_not_found'],
    // refusal
    [{ stopReason: 'refusal' }, 'refusal', 'refusal'],
    [{ stopReason: 'refusal', status: 200 }, 'refusal', 'refusal'],
    // nothing to go by
    [{}, 'unknown', 'unknown'],
    [{ sdkError: 'unknown' }, 'unknown', 'unknown'],
];

for (const [input, cls, code] of TABLE) {
    test(`${JSON.stringify(input)} → ${cls} / ${code}`, () => {
        const c = classifyProviderError(input);
        assert.equal(c.class, cls);
        assert.equal(c.code, code);
        assert.equal(c.retryable, cls === 'transient');
    });
}

test('a 429 is classified by its status and error code, never by a missing retry-after', () => {
    assert.equal(classifyProviderError({ status: 429 }).class, 'transient');
    assert.equal(classifyProviderError({ status: 429, errorCode: 'enforced_spend_limit_reached' }).class, 'billing_auth');
});

test('the HTTP status wins over what the harness calls the error', () => {
    assert.equal(classifyProviderError({ status: 402, sdkError: 'rate_limit' }).class, 'billing_auth');
});

test('each class leads to one scan outcome', () => {
    assert.deepEqual(outcomeForClass('transient'), { state: 'failed', code: 'provider_unavailable' });
    assert.deepEqual(outcomeForClass('billing_auth'), { state: 'blocked', code: 'provider_blocked' });
    assert.deepEqual(outcomeForClass('request'), { state: 'failed', code: 'build' });
    assert.deepEqual(outcomeForClass('refusal'), { state: 'refused', code: null });
});
