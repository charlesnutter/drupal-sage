import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    tailwindcss(),
  ],

  build: {
    // Output to dist/ — referenced by sage_theme.libraries.yml
    outDir: 'dist',
    emptyOutDir: true,

    rollupOptions: {
      input: 'src/main.js',

      output: {
        // Predictable filenames: Drupal's library system needs stable paths.
        // No content hashing — cache busting is handled by Drupal's
        // aggregation system and query strings.
        entryFileNames: 'main.js',
        chunkFileNames: 'chunks/[name].js',
        assetFileNames: (asset) => {
          if (asset.name?.endsWith('.css')) return 'main.css'
          return 'assets/[name][extname]'
        },
      },
    },
  },
})
