import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";
import { defineConfig } from "vitest/config";

const API = /^\/api(?:\/|$)/;

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      // Ask the user before activating a new version (never reload over unsaved work).
      registerType: "prompt",
      manifest: {
        name: "Vitalcare Training Hub",
        short_name: "Vitalcare",
        description: "Care training with a record you can check. Vitalcare Training Hub Ltd.",
        display: "standalone",
        theme_color: "#1b2e6b",
        background_color: "#f8fafc",
        icons: [
          { src: "/icons/pwa-192.png", sizes: "192x192", type: "image/png" },
          { src: "/icons/pwa-512.png", sizes: "512x512", type: "image/png" },
          {
            src: "/icons/pwa-maskable-512.png",
            sizes: "512x512",
            type: "image/png",
            purpose: "maskable",
          },
        ],
      },
      workbox: {
        // The API is never served from the service worker cache.
        navigateFallbackDenylist: [API],
        runtimeCaching: [
          { urlPattern: ({ url }) => API.test(url.pathname), handler: "NetworkOnly" },
        ],
      },
    }),
  ],
  server: {
    // Fixed ports from KIT/PORTS.md. `pnpm dev` also starts the PHP API on the API port.
    host: "127.0.0.1",
    port: 5175,
    strictPort: true,
    proxy: { "/api": "http://127.0.0.1:3175" },
  },
  preview: { host: "127.0.0.1", port: 5175, strictPort: true },
  test: {
    // Component tests opt in to jsdom with a "@vitest-environment jsdom" docblock.
    environment: "node",
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
    setupFiles: ["src/test/setup.ts"],
  },
});
