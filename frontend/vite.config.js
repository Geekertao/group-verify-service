import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src')
    }
  },
  base: '/static/verify/',
  build: {
    outDir: '../backend/public/static/verify',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        // 依赖全部并进一个 chunk：把 arco/vue/vendor 拆开会在 ES module
        // 初始化顺序上打结，构建后页面直接白屏（Cannot access 'D' before initialization）。
        manualChunks(id) {
          return String(id || '').includes('node_modules') ? 'vendor' : undefined;
        }
      }
    }
  }
});

