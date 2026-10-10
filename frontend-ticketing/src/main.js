import { createApp } from 'vue'
import { createPinia } from 'pinia'

import './assets/main.css'
import 'primeicons/primeicons.css'

import App from './App.vue'
import router from './router'

document.documentElement.dataset.theme = localStorage.getItem('supportdesk-theme') === 'dark' ? 'dark' : 'light'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
