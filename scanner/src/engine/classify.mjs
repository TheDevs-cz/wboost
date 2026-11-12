// Provider error classifier (architecture §7). Errors are classified by HTTP status and error type —
// never by a missing header. One function for both engines: the Messages loop passes what the API
// returned, the Agent SDK passes what the harness reports on its messages.
//
// Classes: transient (retry, then `failed provider_unavailable`) · billing_auth (confirm with a probe,
// then the provider is `blocked`) · request (`failed build`) · refusal (`refused`) · unknown.

const SDK_ERRORS = {
    authentication_failed: ['billing_auth', 'authentication'],
    oauth_org_not_allowed: ['billing_auth', 'authentication'],
    account_on_hold: ['billing_auth', 'account_on_hold'],
    verification_required: ['billing_auth', 'account_on_hold'],
    cloud_credential_error: ['billing_auth', 'authentication'],
    billing_error: ['billing_auth', 'billing'],
    rate_limit: ['transient', 'rate_limit'],
    overloaded: ['transient', 'overloaded'],
    server_error: ['transient', 'server_error'],
    invalid_request: ['request', 'invalid_request'],
    model_not_found: ['request', 'model_not_found'],
    max_output_tokens: ['request', 'max_output_tokens'],
    unknown: ['unknown', 'unknown'],
};

const USAGE_LIMIT = /usage limit|spend limit|spending limit|credit balance|workspace .*limit/i;

/**
 * @param e {status?, type?, errorCode?, message?, sdkError?, stopReason?, network?, midStream?, planLimit?}
 *   status     HTTP status of the provider's answer (null for a connection error)
 *   type       API error type (`rate_limit_error`, `overloaded_error`, `billing_error`, …)
 *   errorCode  `error.details.error_code` when present (`enforced_spend_limit_reached`)
 *   sdkError   the Agent SDK's `error` on an assistant / retry message
 *   planLimit  the subscription's own limit was reported as reached (development runs)
 * → {class, code, retryable}
 */
export function classifyProviderError(e = {}) {
    const out = (cls, code) => ({ class: cls, code, retryable: cls === 'transient' });
    if (e.stopReason === 'refusal') return out('refusal', 'refusal');
    if (e.planLimit) return out('billing_auth', 'plan_limit');
    const status = typeof e.status === 'number' ? e.status : null;
    const message = String(e.message ?? '');
    if (status !== null) {
        if (status === 429) {
            if (e.errorCode === 'enforced_spend_limit_reached') return out('billing_auth', 'spend_limit');
            return out('transient', 'rate_limit');
        }
        if (status === 402) return out('billing_auth', 'billing');
        if (status === 401) return out('billing_auth', 'authentication');
        if (status === 403) return out('billing_auth', 'permission');
        if (status === 400) return USAGE_LIMIT.test(message) ? out('billing_auth', 'usage_limit') : out('request', 'invalid_request');
        if (status === 404) return out('request', 'model_not_found');
        if (status === 413) return out('request', 'request_too_large');
        if (status === 408 || status === 409) return out('transient', 'timeout');
        if (status === 529) return out('transient', 'overloaded');
        if (status >= 500) return out('transient', 'server_error');
        if (status >= 400) return out('request', 'invalid_request');
    }
    if (e.type) {
        if (e.type === 'rate_limit_error') return out('transient', 'rate_limit');
        if (e.type === 'overloaded_error') return out('transient', 'overloaded');
        if (e.type === 'api_error' || e.type === 'timeout_error') return out('transient', 'server_error');
        if (e.type === 'billing_error') return out('billing_auth', 'billing');
        if (e.type === 'authentication_error') return out('billing_auth', 'authentication');
        if (e.type === 'permission_error') return out('billing_auth', 'permission');
        if (e.type === 'not_found_error') return out('request', 'model_not_found');
        if (e.type === 'request_too_large') return out('request', 'request_too_large');
        if (e.type === 'invalid_request_error') return USAGE_LIMIT.test(message) ? out('billing_auth', 'usage_limit') : out('request', 'invalid_request');
    }
    if (e.sdkError && SDK_ERRORS[e.sdkError]) {
        const [cls, code] = SDK_ERRORS[e.sdkError];
        // the harness reports a spend cap and a throttle both as `rate_limit`: the status decided above when known
        return out(cls, code);
    }
    if (e.network || e.midStream) return out('transient', e.midStream ? 'stream_interrupted' : 'network');
    return out('unknown', 'unknown');
}

/** The scan outcome a classified provider failure leads to (codes of architecture §5.6). */
export function outcomeForClass(cls) {
    return { transient: { state: 'failed', code: 'provider_unavailable' }, billing_auth: { state: 'blocked', code: 'provider_blocked' }, request: { state: 'failed', code: 'build' }, refusal: { state: 'refused', code: null }, unknown: { state: 'failed', code: 'build' } }[cls];
}
