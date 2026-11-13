import { cpSync, readFileSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const root = dirname(fileURLToPath(import.meta.url));

/**
 * pdf.js fetches some of what it needs while it runs, by address: the decoders for fax, JBIG2 and
 * JPEG 2000 pictures (which is how office scanners store pages), the character maps, the standard
 * fonts and the colour profile. They are not part of the bundle, so they are copied from the
 * installed package to public/pdfjs-assets/<version>/ each time a build or the dev server starts.
 * The folder is not committed. config/modules/document_reading.php names the same version, and a
 * test compares the two.
 */
function pdfjsAssets() {
    return {
        name: 'pdfjs-assets',
        buildStart() {
            const from = resolve(root, 'node_modules/pdfjs-dist');
            const to = resolve(root, 'public/pdfjs-assets');
            const { version } = JSON.parse(readFileSync(resolve(from, 'package.json'), 'utf8'));

            // An older version's folder is of no use to anyone once the package has moved on.
            rmSync(to, { recursive: true, force: true });

            for (const folder of ['wasm', 'cmaps', 'standard_fonts', 'iccs']) {
                cpSync(resolve(from, folder), resolve(to, version, folder), { recursive: true });
            }
        },
    };
}

export default defineConfig({
    plugins: [
        pdfjsAssets(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/drawings/viewer.js', 'resources/js/reader/capture.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
