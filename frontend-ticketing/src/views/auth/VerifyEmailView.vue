<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthFrame from '@/layout/AuthFrame.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const busy = ref(false)
const message = ref('')
const error = ref('')
const delivery = computed(() => auth.verificationDelivery)
const deliveryError = computed(() => error.value || (delivery.value?.status === 'unavailable'
    ? delivery.value.message || 'We could not send the verification email. Please try again later.'
    : ''))
async function resend() {
    busy.value = true; error.value = ''; message.value = ''
    try {
        await auth.resendVerification()
        if (delivery.value?.status === 'sent') message.value = delivery.value.message || 'Verification email sent. Check your inbox and spam folder.'
        else if (delivery.value?.status !== 'unavailable') message.value = 'Verification email requested. Check your inbox and spam folder.'
    }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
async function checkAgain() {
    busy.value = true; error.value = ''; message.value = ''
    try {
        auth.ready = false
        await auth.restoreSession()
        if (!auth.authenticated) await router.replace('/auth/login')
        else if (!auth.emailUnverified) await router.replace('/dashboard')
        else message.value = 'Your email is not verified yet. Open the verification link from your email.'
    }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame>
        <div class="auth-card verify-card">
            <span class="verify-icon"><i class="pi pi-envelope" /></span><span class="eyebrow">ONE MORE STEP</span><h2>Verify your email</h2>
            <p v-if="delivery?.status === 'sent'" class="muted">A verification link was sent to <strong>{{ auth.user?.email }}</strong>. Check your inbox and spam folder, then open the link before using the ticketing workspace.</p>
            <p v-else-if="delivery?.status === 'unavailable'" class="muted">Your account is ready, but we could not send a verification email to <strong>{{ auth.user?.email }}</strong>. Use the resend button to try again.</p>
            <p v-else class="muted">Verify <strong>{{ auth.user?.email }}</strong> before using the ticketing workspace. Open the verification link from your email, or request a new one below.</p>
            <p v-if="message" class="form-success" role="status">{{ message }}</p>
            <p v-if="deliveryError" class="form-error" role="alert">{{ deliveryError }}</p>
            <div class="form-stack"><button class="button button-primary button-wide" :disabled="busy" @click="checkAgain">I verified my email</button><button class="button button-secondary button-wide" :disabled="busy" @click="resend">{{ busy ? 'Please wait…' : 'Resend verification email' }}</button><button class="text-action" :disabled="busy" @click="auth.signOut().then(() => router.replace('/auth/login'))">Sign out</button></div>
        </div>
    </AuthFrame>
</template>
