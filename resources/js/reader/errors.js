// What the capture script says when a reader route answers with a failure. The module's own
// refusals carry a code and a sentence in the person's language, and those are passed on as they
// are. Anything else, such as a proxy's error page, an expired session, a throttle or a plain
// permission refusal, carries no sentence a person should see, so a word of the partial is used
// instead. It needs nothing from the browser, so it can be run on its own.

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
 * The ReaderError for a failed answer. `data` is the answer's body when it was JSON, otherwise an
 * empty object; `say` turns a word of the partial into its sentence.
 */
export function refusalOf(status, data, say) {
    const body = data && typeof data === 'object' ? data : {};
    const own = typeof body.code === 'string' && body.code !== '';
    const errors = body.errors && typeof body.errors === 'object' ? Object.values(body.errors) : [];
    const firstError = Array.isArray(errors[0]) && typeof errors[0][0] === 'string' ? errors[0][0] : null;
    const code = own ? body.code : `http_${status}`;

    // The module's own refusal, and a form check of the module's own: their sentences are already in the person's language.
    if (own && typeof body.message === 'string' && body.message !== '') {
        return new ReaderError(code, firstError ?? body.message, body, status);
    }

    if (status === 422 && firstError) {
        return new ReaderError(code, firstError, body, status);
    }

    return new ReaderError(code, say(wordForStatus(status)), body, status);
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

    if (error.code === 'not_stored') {
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
    if (last instanceof ReaderError && !last.code.startsWith('http_')) {
        return last;
    }

    return new ReaderError('upload_failed', say('upload_failed', { n: pageNo }), { cause: last?.code ?? last?.message ?? null }, last?.status ?? 0);
}
