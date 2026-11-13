// The size a page picture is drawn at before it is uploaded. This is the same arithmetic as
// App\Services\DocumentReading\ImageTokens on the server, which has the last word: a picture the
// server finds too large is refused there. A test runs both over the same sizes.
//
// The reader looks at a picture in patches of 28 by 28 pixels, one token each. A picture is read
// at the size it was sent as long as no side is longer than MAX_EDGE once padded to whole patches
// and it has no more than MAX_TOKENS patches.

export const PATCH = 28;
export const MAX_EDGE = 2576;
export const MAX_TOKENS = 4784;

export function imageTokens(width, height) {
    return Math.ceil(width / PATCH) * Math.ceil(height / PATCH);
}

export function fits(width, height, maxEdge = MAX_EDGE, maxTokens = MAX_TOKENS) {
    return width >= 1 && height >= 1
        && Math.ceil(width / PATCH) * PATCH <= maxEdge
        && Math.ceil(height / PATCH) * PATCH <= maxEdge
        && imageTokens(width, height) <= maxTokens;
}

// Halves go to the even neighbour, as the provider's own resizing does.
function roundHalfEven(value) {
    const floor = Math.floor(value);
    const rest = value - floor;

    if (rest !== 0.5) {
        return rest > 0.5 ? floor + 1 : floor;
    }

    return floor % 2 === 0 ? floor : floor + 1;
}

/** The size the provider would shrink a picture to, as [width, height]. A picture that already fits comes back unchanged. */
export function resized(width, height, maxEdge = MAX_EDGE, maxTokens = MAX_TOKENS) {
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) {
        throw new RangeError('A picture has at least one whole pixel each way.');
    }

    if (fits(width, height, maxEdge, maxTokens)) {
        return [width, height];
    }

    if (height > width) {
        const [tall, wide] = resized(height, width, maxEdge, maxTokens);

        return [wide, tall];
    }

    const aspect = width / height;
    const short = (long) => Math.max(roundHalfEven(long / aspect), 1);
    let low = 1;
    let high = width;

    while (low + 1 < high) {
        const middle = Math.floor((low + high) / 2);

        if (fits(middle, short(middle), maxEdge, maxTokens)) {
            low = middle;
        } else {
            high = middle;
        }
    }

    return [low, short(low)];
}

/**
 * The size to draw a page of this shape at, as [width, height]: the largest the reader takes as
 * sent. The shrinking function alone would leave an A4 page at its 595 by 842 points, so it is
 * applied to eight times the size. An A4 page comes out at 1622 by 2296.
 */
export function targetSize(width, height) {
    return resized(Math.max(1, Math.round(width)) * 8, Math.max(1, Math.round(height)) * 8);
}

/** A photo is never enlarged: it is shrunk to the largest size the reader takes, or kept as it is. */
export function photoSize(width, height) {
    return resized(Math.max(1, Math.round(width)), Math.max(1, Math.round(height)));
}

/** Sizes worked out by hand from the provider's reference function. The self-check runs them in the browser. */
export const VECTORS = [
    { from: [595, 842], to: [1622, 2296], tokens: 4756 },
    { from: [842, 1191], to: [1623, 2296], tokens: 4756 },
    { from: [612, 1008], to: [1496, 2464], tokens: 4752 },
    { from: [612, 792], to: [1688, 2184], tokens: 4758 },
    { from: [4000, 3000], to: [2212, 1659], tokens: 4740 },
];

export const TOKEN_VECTORS = [
    { size: [1628, 2303], tokens: 4897 },
    { size: [1900, 1900], tokens: 4624 },
];
