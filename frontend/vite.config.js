import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    tailwindcss(),
    vue({
      customElement: /WebComponent\.ce\.vue$/,
      template: {
        compilerOptions: {
          isCustomElement: (tag) => tag === 'barcamp-schedule'
        }
      }
    }),
  ],
  root: '.',
  build: {
    rolldownOptions: {
      input: {
        main: 'index.html',
        embed: 'src/web-component-entry.js'
      },
      output: {
        entryFileNames: (chunkInfo) => {
          return chunkInfo.name === 'embed' ? 'embed.js' : 'assets/[name]-[hash].js';
        },
        manualChunks: (id) => {
          if (id.includes('node_modules')) {
            // Group other large or common libraries
            if (id.includes('vue')) {
              return 'vendor-core';
            }
          }
        }
      }
    }
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:8000',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, '/api')
      }
    }
  }
})
