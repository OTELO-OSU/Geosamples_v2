/**
 * Vite — même outillage qu'ORDaR v2 (Vue 3 + Vuetify).
 * En développement, /api est relayé vers le backend Symfony (php -S ou conteneur php).
 */
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import path from 'path'

export default defineConfig({
  plugins: [vue(), vuetify({ autoImport: true })],
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: process.env.VITE_API_PROXY || 'http://127.0.0.1:8000', changeOrigin: false },
    },
  },
  build: {
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      output: {
        manualChunks: {
          leaflet: ['leaflet', 'leaflet.markercluster'],
          vuetify: ['vuetify'],
        },
      },
    },
  },
})
