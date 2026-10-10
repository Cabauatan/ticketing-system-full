<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useProfileStore } from '@/stores/profile'

const auth = useAuthStore()
const profileStore = useProfileStore()
const form = ref({ name: '', email: '' })
const password = ref({ current_password: '', password: '', password_confirmation: '' })
const avatarUrl = ref(null)
const fileInput = ref(null)
const message = ref('')
const error = ref('')
const avatarBusy = ref(false)
const passwordBusy = ref(false)
let previewUrl = null

function setAvatar(url) {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
    previewUrl = null
    avatarUrl.value = url || null
}

async function load() {
    try {
        const user = await profileStore.fetch()
        form.value = { name: user.name, email: user.email }
        setAvatar(user.avatar_url)
    } catch {
        form.value = { name: auth.user?.name || '', email: auth.user?.email || '' }
        setAvatar(auth.user?.avatar_url)
    }
}

async function save() {
    message.value = ''
    error.value = ''
    try {
        const data = await profileStore.save(form.value)
        if (data?.user) {
            auth.user = { ...auth.user, ...data.user }
            auth.emailUnverified = !data.user.email_verified_at
        }
        message.value = 'Profile saved.'
        await load()
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not save your profile.'
    }
}

async function uploadAvatar(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    message.value = ''
    error.value = ''
    const localPreview = URL.createObjectURL(file)
    if (previewUrl) URL.revokeObjectURL(previewUrl)
    previewUrl = localPreview
    avatarUrl.value = localPreview
    avatarBusy.value = true

    try {
        const body = new FormData()
        body.append('avatar', file)
        const user = await profileStore.uploadAvatar(body)
        auth.user = { ...auth.user, ...user }
        setAvatar(user.avatar_url)
        message.value = 'Profile photo updated.'
    } catch (failure) {
        setAvatar(auth.user?.avatar_url)
        error.value = failure.response?.data?.message || 'Could not upload the profile photo.'
    } finally {
        avatarBusy.value = false
    }
}

async function removeAvatar() {
    message.value = ''
    error.value = ''
    avatarBusy.value = true
    try {
        const user = await profileStore.removeAvatar()
        auth.user = { ...auth.user, ...user }
        setAvatar(null)
        message.value = 'Profile photo removed.'
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not remove the profile photo.'
    } finally {
        avatarBusy.value = false
    }
}

async function changePassword() {
    message.value = ''
    error.value = ''
    passwordBusy.value = true
    try {
        await profileStore.updatePassword(password.value)
        message.value = 'Password updated.'
        password.value = { current_password: '', password: '', password_confirmation: '' }
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not update the password.'
    } finally {
        passwordBusy.value = false
    }
}

const refresh = () => load()
onMounted(() => {
    load()
    window.addEventListener('supportdesk:refresh', refresh)
})
onBeforeUnmount(() => {
    window.removeEventListener('supportdesk:refresh', refresh)
    if (previewUrl) URL.revokeObjectURL(previewUrl)
})
</script>

<template>
    <div class="page-heading">
        <div>
            <h2>Account settings</h2>
            <p>Manage your account details and sign-in password.</p>
        </div>
    </div>

    <p v-if="message" class="success-box">{{ message }}</p>
    <p v-if="error" class="error-box">{{ error }}</p>

    <form class="panel profile-settings-panel" @submit.prevent="save">
        <div class="profile-section-heading">
            <div>
                <h3>Profile</h3>
                <p>Your name and profile photo are visible to your teammates.</p>
            </div>
        </div>

        <div class="profile-avatar-editor">
            <div class="profile-avatar-preview" aria-label="Profile photo preview">
                <img v-if="avatarUrl" :src="avatarUrl" alt="Your profile photo" />
                <span v-else>{{
                    (form.name || auth.user?.name || 'U').slice(0, 1).toUpperCase()
                }}</span>
            </div>
            <div class="profile-avatar-actions">
                <strong>Profile photo</strong>
                <p>Use a JPG, PNG, or WebP image up to 5 MB.</p>
                <div class="profile-avatar-buttons">
                    <button
                        class="button button-secondary"
                        type="button"
                        :disabled="avatarBusy"
                        @click="fileInput?.click()"
                    >
                        <i :class="avatarBusy ? 'pi pi-spin pi-spinner' : 'pi pi-upload'" />
                        {{
                            avatarBusy ? 'Uploading…' : avatarUrl ? 'Change photo' : 'Upload photo'
                        }}
                    </button>
                    <button
                        v-if="avatarUrl && !previewUrl"
                        class="button button-quiet"
                        type="button"
                        :disabled="avatarBusy"
                        @click="removeAvatar"
                    >
                        Remove
                    </button>
                    <input
                        ref="fileInput"
                        class="visually-hidden"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        @change="uploadAvatar"
                    />
                </div>
            </div>
        </div>

        <div class="form-grid profile-details-grid">
            <label class="field-label"
                >Name<input v-model="form.name" class="input" required maxlength="255"
            /></label>
            <label class="field-label"
                >Email<input
                    v-model="form.email"
                    type="email"
                    class="input"
                    required
                    maxlength="255"
            /></label>
        </div>
        <button class="button button-primary" style="margin-top: 16px">Save profile</button>
    </form>

    <form class="panel" @submit.prevent="changePassword">
        <h3>Change password</h3>
        <div class="form-grid">
            <label class="field-label"
                >Current password<input
                    v-model="password.current_password"
                    type="password"
                    class="input"
                    required
                    autocomplete="current-password"
            /></label>
            <label class="field-label"
                >New password<input
                    v-model="password.password"
                    type="password"
                    class="input"
                    required
                    minlength="8"
                    autocomplete="new-password"
            /></label>
            <label class="field-label"
                >Confirm password<input
                    v-model="password.password_confirmation"
                    type="password"
                    class="input"
                    required
                    autocomplete="new-password"
            /></label>
        </div>
        <button class="button button-primary" style="margin-top: 14px" :disabled="passwordBusy">
            {{ passwordBusy ? 'Updating…' : 'Update password' }}
        </button>
    </form>
</template>
