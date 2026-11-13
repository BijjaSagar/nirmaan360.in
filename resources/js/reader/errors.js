// What the capture script says when a reader route answers with a failure, or when a request never
// reached the server. The person always reads a sentence in their own language:
//   - the server's own sentence, when it sent one: the module's refusals carry a code with it, and
//     some refusals (a plan that does not include the module, a person who may not use the reader)
//     carry only the sentence, already in the person's language;
//   - otherwise a word of the partial for the kind of failure, because what the framework or a proxy
//     sends (an error page, "This action is unauthorized.", "Too Many Attempts.") is not written
//     for a person;
//   - and the partial's word about the connection when the request never reached the server.
// It needs nothing from the browser, so it can be run on its own.

/** A refusal with a code the calling screen can act on: from the server, or raised here before anything was sent. */
export class ReaderError extends Error {
    constructor(code, message = '', details = {}, status = 0) {
        super(message);
        this.name = 'ReaderError';
        this.code = code;
        this.details = details;
        this.status = status;
    }
}

/** The code of a request that never reached the server, so no answer came back at all. */
export const NETWORK = 'network';

// The sentences the framework itself answers with. None of them is written for a person, and none is
// translated, so a word of the partial is shown instead. Anything else a 4xx answer carries as its
// message is a sentence the app wrote for the person, in their language.
const FRAMEWORK_SENTENCES = [
    'This action is unauthorized.', 'Unauthenticated.', 'Unauthorized', 'Unauthorized.', 'Forbidden',
    'CSRF token mismatch.', 'Page Expired', 'Invalid signature.', 'Too Many Attempts.', 'Too Many Requests',
    'Not Found', 'Not found.', 'Bad hostname provided.', 'Method Not Allowed', 'Bad Request', 'Conflict', 'Gone',
    'Payload Too Large', 'Content Too Large', 'The POST data is too large.', 'Unprocessable Content', 'Unprocessable Entity',
    'Server Error', 'Service Unavailable',
];

const FRAMEWORK_PATTERNS = [
    /^No query results for model\b/,
    /^The route .* could not be found\.?$/,
    /\bmethod is not supported for (this )?route\b/,
    /^Case \[.*\] not found on Backed Enum\b/,
];

/** The word of the partial for a failure that came with no sentence of the module's own. */
export function wordForStatus(status) {
    if (status === 401 || status === 419) {
        return 'signed_out';
    }

    if (status === 403) {
        return 'not_allowed';
    }

    if (status === 404) {
        return 'not_found';
    }

    if (status === 413) {
        return 'too_large';
    }

    if (status === 429) {
        return 'too_many';
    }

    return 'server_failed';
}

/**
 * Whether the message of an answer without a code is a sentence the app wrote for the person. A
 * server failure (5xx) never is: in debug mode its message is the exception's own text. Signed out,
 * too large and too many tries are always the framework's or a proxy's, and the partial has a word
 * for each.
 */
export function isOwnSentence(status, message) {
    if (typeof message !== 'string' || message.trim() === '') {
        return false;
    }

    if (status < 400 || status >= 500 || [401, 413, 419, 429].includes(status)) {
        return false;
    }

    const text = message.trim();

    return !FRAMEWORK_SENTENCES.includes(text) && !FRAMEWORK_PATTERNS.some((pattern) => pattern.test(text));
}

/**
 * The ReaderError for a failed answer. `data` is the answer's body when it was JSON, otherwise an
 * empty object; `say` turns a word of the partial into its sentence.
 */
export function refusalOf(status, data, say) {
    const body = data && typeof data === 'object' ? data : {};
    const own = typeof body.code === 'string' && body.code !== '';
    const errors = body.errors && typeof body.errors === 'object' ? Object.values(body.errors) : [];
    const firstError = Array.isArray(errors[0]) && typeof errors[0][0] === 'string' ? errors[0][0] : null;
    const message = typeof body.message === 'string' ? body.message.trim() : '';
    const code = own ? body.code : `http_${status}`;

    // The module's own refusal, and a form check of the module's own: their sentences are already in the person's language.
    if (own && message !== '') {
        return new ReaderError(code, firstError ?? message, body, status);
    }

    if (status === 422 && firstError) {
        return new ReaderError(code, firstError, body, status);
    }

    // A refusal that carries only a sentence the app wrote, such as the plan not including the module.
    if (isOwnSentence(status, message)) {
        return new ReaderError(code, message, body, status);
    }

    return new ReaderError(code, say(wordForStatus(status)), body, status);
}

/**
 * The ReaderError for a request that never reached the server: fetch() rejected, because the
 * connection dropped, the network is off or the address could not be reached. No answer came back,
 * so there is no status and nothing of the server's to show.
 */
export function unreached(error, say) {
    if (error instanceof ReaderError) {
        return error;
    }

    return new ReaderError(NETWORK, say(NETWORK), { reason: error?.name ?? null }, 0);
}

/**
 * Whether an upload that failed may be tried again: a failure of the connection or the server, or
 * the server saying it could not keep the picture. A refusal of the picture itself, or of the
 * person, is final at once.
 */
export function worthRetrying(error) {
    if (!(error instanceof ReaderError)) {
        return true;
    }

    if (error.code === 'not_stored' || error.code === NETWORK) {
        return true;
    }

    return !(error.status >= 400 && error.status < 500 && ![408, 425, 429].includes(error.status));
}

/**
 * What an upload that kept failing ends with. The module's own refusal is passed on with its
 * sentence; a failure of the connection or of the server becomes the partial's sentence about the
 * page that could not be uploaded.
 */
export function uploadFailure(last, pageNo, say) {
    if (last instanceof ReaderError && !last.code.startsWith('http_') && last.code !== NETWORK) {
        return last;
    }

    return new ReaderError('upload_failed', say('upload_failed', { n: pageNo }), { cause: last?.code ?? last?.message ?? null }, last?.status ?? 0);
}
