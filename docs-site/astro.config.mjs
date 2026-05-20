// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
//
// `base` matches the GitHub Pages path: <user>.github.io/wp-dario-provider/.
// `site` is the canonical absolute URL used by Astro to generate sitemap +
// social meta. Leave both inline rather than a config var so the build is
// reproducible without env wiring.
export default defineConfig({
  site: 'https://procyon-creative.github.io',
  base: '/wp-dario-provider',
  vite: {
    plugins: [tailwindcss()],
  },
});
