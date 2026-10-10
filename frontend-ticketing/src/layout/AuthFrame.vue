<template>
    <main class="auth-shell">
        <section class="auth-aside">
            <RouterLink to="/auth/login" class="brand"><span class="brand-mark"><i class="pi pi-ticket" /></span><span><b>Support Desk</b><small>IT Service Workspace</small></span></RouterLink>
            <div class="auth-aside-copy">
                <span class="eyebrow">IT SERVICE MANAGEMENT</span>
                <h1>Good support starts with a clear request.</h1>
                <p>Track tickets, coordinate your support team, and keep equipment accountable from one workspace.</p>
            </div>
            <div class="muted">Secure service portal</div>
        </section>
        <section class="auth-panel"><slot /></section>
    </main>
</template>
