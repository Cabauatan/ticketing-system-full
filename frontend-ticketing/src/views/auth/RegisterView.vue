<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthFrame from '@/layout/AuthFrame.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const form = ref({ name: '', email: '', password: '', password_confirmation: '' })
const error = ref('')
const fieldErrors = ref({})
const accountCreated = ref(false)
const busy = ref(false)

function fieldError(field) {
    const messages = fieldErrors.value[field]
    return Array.isArray(messages) ? messages.join(' ') : messages || ''
}

async function submit() {
    error.value = ''
    fieldErrors.value = {}
    busy.value = true
    try { await auth.register(form.value); await router.replace('/verify-email') }
    catch (failure) {
        const details = apiError(failure)
        fieldErrors.value = details.errors
        error.value = details.message
        accountCreated.value = Boolean(failure.accountCreated)
    }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame>
        <div class="auth-card">
            <span class="eyebrow">GET STARTED</span><h2>Create your account</h2>
            <p class="muted">Use your work email so the support team can identify your requests.</p>
            <form v-if="!accountCreated" class="form-stack" @submit.prevent="submit">
                <label class="field-label">Full name<input v-model.trim="form.name" required autocomplete="name" placeholder="Your name" :aria-invalid="Boolean(fieldError('name'))" :aria-describedby="fieldError('name') ? 'register-name-error' : undefined" /><small v-if="fieldError('name')" id="register-name-error" class="form-error">{{ fieldError('name') }}</small></label>
                <label class="field-label">Work email<input v-model.trim="form.email" type="email" required autocomplete="email" placeholder="you@company.com" :aria-invalid="Boolean(fieldError('email'))" :aria-describedby="fieldError('email') ? 'register-email-error' : undefined" /><small v-if="fieldError('email')" id="register-email-error" class="form-error">{{ fieldError('email') }}</small></label>
                <label class="field-label">Password<input v-model="form.password" type="password" required autocomplete="new-password" minlength="12" placeholder="Create a password" :aria-invalid="Boolean(fieldError('password'))" :aria-describedby="fieldError('password') ? 'register-password-help register-password-error' : 'register-password-help'" /><small id="register-password-help" class="field-help">Use at least 12 characters, including uppercase and lowercase letters, a number, and a symbol.</small><small v-if="fieldError('password')" id="register-password-error" class="form-error">{{ fieldError('password') }}</small></label>
                <label class="field-label">Confirm password<input v-model="form.password_confirmation" type="password" required autocomplete="new-password" placeholder="Enter it again" :aria-invalid="Boolean(fieldError('password_confirmation'))" :aria-describedby="fieldError('password_confirmation') ? 'register-confirmation-error' : undefined" /><small v-if="fieldError('password_confirmation')" id="register-confirmation-error" class="form-error">{{ fieldError('password_confirmation') }}</small></label>
                <p v-if="error" class="form-error" role="alert">{{ error }}</p>
                <button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Creating account…' : 'Create account' }}</button>
            </form>
            <div v-else class="form-stack"><p class="form-error" role="alert">{{ error }}</p><RouterLink class="button button-primary button-wide" to="/auth/login">Sign in to continue</RouterLink></div>
            <div class="auth-links single">Already have an account? <RouterLink to="/auth/login">Sign in</RouterLink></div>
        </div>
    </AuthFrame>
</template>
