import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import tailwindcss from '@tailwindcss/vite'
import { PrimeVueResolver } from '@primevue/auto-import-resolver'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
    const frontendRoot = fileURLToPath(new URL('.', import.meta.url))
    const backendRoot = fileURLToPath(new URL('..', import.meta.url))
    const frontendEnv = loadEnv(mode, frontendRoot, '')
    const backendEnv = loadEnv(mode, backendRoot, '')
    const env = { ...backendEnv, ...frontendEnv }
    const apiTarget = env.VITE_API_PROXY_TARGET || env.APP_URL || 'http://192.168.100.51:8001'
    const devHost = env.VITE_DEV_HOST || '0.0.0.0'
    const devPort = Number(env.VITE_DEV_PORT || 5001)
    const proxyPaths = [
        '/api',
        '/login',
        '/logout',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/email',
        '/two-factor-challenge',
        '/user',
        '/broadcasting/auth',
        '/passkeys',
    ]
    const proxy = Object.fromEntries(
        proxyPaths.map((path) => [
            path,
            {
                target: apiTarget,
                changeOrigin: false,
                secure: false,
                bypass: (request) =>
                    path === '/reset-password' && request.method === 'GET'
                        ? request.url
                        : undefined,
            },
        ]),
    )

    return {
        plugins: [
            vue(),
            Components({
                resolvers: [PrimeVueResolver()],
            }),
            AutoImport({
                eslintrc: {
                    enabled: true,
                    filepath: './.eslintrc-auto-import.json',
                },
                imports: ['vue', 'vue-router', 'pinia'],
                vueTemplate: true,
            }),
            tailwindcss(),
        ],

        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./src', import.meta.url)),
            },
        },
        server: {
            host: devHost,
            port: devPort,
            strictPort: true,
            proxy,
        },
        preview: {
            host: devHost,
            port: devPort,
            strictPort: true,
            proxy,
        },
        define: {
            'import.meta.env.VITE_REVERB_APP_KEY': JSON.stringify(env.VITE_REVERB_APP_KEY || ''),
            'import.meta.env.VITE_REVERB_HOST': JSON.stringify(
                env.VITE_REVERB_HOST || '',
            ),
            'import.meta.env.VITE_REVERB_PORT': JSON.stringify(
                env.VITE_REVERB_PORT || env.REVERB_PORT || '8081',
            ),
            'import.meta.env.VITE_REVERB_SCHEME': JSON.stringify(
                env.VITE_REVERB_SCHEME || env.REVERB_SCHEME || 'http',
            ),
        },
        build: {
            chunkSizeWarningLimit: 5000,
            minify: 'terser',
            terserOptions: {
                compress: {
                    drop_console: true,
                    // drop_debugger: true,
                    pure_funcs: ['console.log', 'console.debug', 'console.info', 'console.warn'],
                },
            },
        },
    }
})
