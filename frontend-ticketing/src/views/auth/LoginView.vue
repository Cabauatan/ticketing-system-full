<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AuthFrame from '@/layout/AuthFrame.vue'
import { apiError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const form = ref({ email: '', password: '', remember: true })
const code = ref('')
const recovery = ref(false)
const twoFactor = ref(false)
const busy = ref(false)
const error = ref('')
const showPassword = ref(false)

async function submit() {
    busy.value = true
    error.value = ''
    try {
        if (!twoFactor.value) {
            const result = await auth.signIn(form.value)
            if (result.twoFactor) { twoFactor.value = true; return }
            await router.replace(auth.emailUnverified ? '/verify-email' : (route.query.redirect || '/dashboard'))
        } else {
            await auth.completeTwoFactor(recovery.value ? { recovery_code: code.value } : { code: code.value })
            await router.replace(auth.emailUnverified ? '/verify-email' : (route.query.redirect || '/dashboard'))
        }
    } catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame>
        <div class="auth-card">
            <span class="eyebrow">WELCOME BACK</span>
            <h2>{{ twoFactor ? 'Verify your identity' : 'Sign in to your account' }}</h2>
            <p class="muted">{{ twoFactor ? 'Enter the code from your authenticator app.' : 'Use your registered account to continue.' }}</p>
            <form class="form-stack" @submit.prevent="submit">
                <template v-if="!twoFactor">
                    <label class="field-label">Email address<input v-model.trim="form.email" type="email" autocomplete="username" required placeholder="you@company.com" /></label>
                    <label class="field-label">Password<span class="password-control"><input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required placeholder="Enter your password" /><button type="button" class="password-toggle" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword" @click="showPassword = !showPassword"><i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" /></button></span></label>
                    <label class="check-row"><input v-model="form.remember" type="checkbox" /> Keep me signed in</label>
                </template>
                <template v-else>
                    <label class="field-label">{{ recovery ? 'Recovery code' : 'Authentication code' }}<input v-model.trim="code" :autocomplete="recovery ? 'off' : 'one-time-code'" required autofocus placeholder="Enter your code" /></label>
                    <button class="text-action" type="button" @click="recovery = !recovery; code = ''">Use {{ recovery ? 'authenticator code' : 'recovery code' }} instead</button>
                </template>
                <p v-if="error" class="form-error" role="alert">{{ error }}</p>
                <button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Please wait…' : twoFactor ? 'Verify and continue' : 'Sign in' }}</button>
            </form>
            <div v-if="!twoFactor" class="auth-links"><RouterLink to="/auth/forgot-password">Forgot password?</RouterLink><span>New here? <RouterLink to="/auth/register">Create an account</RouterLink></span></div>
            <button v-else class="text-action back-action" type="button" @click="twoFactor = false; code = ''">Back to sign in</button>
        </div>
        <p class="auth-legal">By signing in, you agree to use this service for authorized support requests.</p>
    </AuthFrame>
</template>
