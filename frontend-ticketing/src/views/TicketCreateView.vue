<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTicketStore } from '@/stores/tickets'

const router = useRouter()
const auth = useAuthStore()
const ticketStore = useTicketStore()
const busy = ref(false)
const error = ref('')
const success = ref('')
const loadingCategories = ref(true)
const categoryError = ref('')
const form = ref({ subject: '', category: '', description: '' })

async function loadCategories() {
    loadingCategories.value = true
    categoryError.value = ''
    try {
        const categories = await ticketStore.fetchCategories()
        if (!categories.some((category) => category.slug === form.value.category)) {
            form.value.category = categories.find((category) => category.slug === 'hardware')?.slug || categories[0]?.slug || ''
        }
    } catch (failure) {
        categoryError.value = failure.response?.data?.message || 'Could not load ticket categories. Please try again.'
    } finally {
        loadingCategories.value = false
    }
}

onMounted(() => {
    loadCategories()
    window.addEventListener('supportdesk:refresh', loadCategories)
})
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', loadCategories))

async function submit() {
    if (loadingCategories.value || categoryError.value || !form.value.category) return
    busy.value = true
    error.value = ''
    success.value = ''
    try {
        const data = await ticketStore.create(form.value)
        if (auth.can('tickets.view')) {
            await router.push(`/tickets/${data.id}`)
        } else {
            success.value = `Ticket #${data.id} was created successfully.`
            form.value.subject = ''
            form.value.description = ''
        }
    } catch (failure) {
        error.value =
            failure.response?.data?.message ||
            Object.values(failure.response?.data?.errors || {})
                .flat()
                .join(' ') ||
            'Could not save the ticket.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="page-heading">
        <div>
            <h2>New ticket</h2>
            <p>Describe your request so it can be routed to the right team.</p>
        </div>
    </div>

    <form class="panel" @submit.prevent="submit">
        <div class="form-grid">
            <label class="field-label full">
                Subject
                <input
                    v-model="form.subject"
                    class="input"
                    required
                    maxlength="200"
                    placeholder="e.g. My laptop will not start"
                />
            </label>
            <label class="field-label">
                Category
                <select v-model="form.category" class="select" required :disabled="loadingCategories || Boolean(categoryError)">
                    <option value="" disabled>{{ loadingCategories ? 'Loading categories…' : 'Select a category' }}</option>
                    <option v-for="category in ticketStore.categories" :key="category.slug" :value="category.slug">{{ category.name }}</option>
                </select>
                <small v-if="!loadingCategories && !categoryError && !ticketStore.categories.length" class="form-error">No active categories are available. Contact an administrator.</small>
            </label>
            <div class="field-label">
                Priority
                <div class="input" aria-label="Default priority: Low">Low</div>
            </div>
            <label class="field-label full">
                Description
                <textarea
                    v-model="form.description"
                    class="textarea"
                    required
                    maxlength="20000"
                    placeholder="What happened, and when did it start?"
                ></textarea>
            </label>
        </div>

        <p v-if="success" class="success-box" role="status">{{ success }}</p>
        <p v-if="error" class="error-box" role="alert">{{ error }}</p>
        <div v-if="categoryError" class="error-box" role="alert">
            {{ categoryError }}
            <button type="button" class="button button-secondary" @click="loadCategories">Try again</button>
        </div>
        <div class="actions" style="margin-top: 18px">
            <button class="button button-primary" :disabled="busy || loadingCategories || Boolean(categoryError) || !form.category">
                {{ busy ? 'Submitting…' : 'Submit ticket' }}
            </button>
            <button
                type="button"
                class="button button-secondary"
                :disabled="busy"
                @click="router.back()"
            >
                Cancel
            </button>
        </div>
    </form>
</template>
