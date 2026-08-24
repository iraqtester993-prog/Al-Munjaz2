import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({ base: process.env.VITE_PUBLIC_BASE || '/', plugins: [vue()], build: { outDir: 'dist', emptyOutDir: true } })
