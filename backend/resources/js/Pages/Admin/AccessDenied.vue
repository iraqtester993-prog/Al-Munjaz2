<script setup>
import { Head, Link } from '@inertiajs/vue3'
</script>

<template>
    <Head title="صلاحيات الحساب" />

    <main class="access-denied" dir="rtl">
        <section class="access-card">
            <span class="access-icon" aria-hidden="true">⌁</span>
            <h1>لم تُسند صلاحيات لهذا الحساب بعد</h1>
            <p>يمكن لمدير النظام تعيين ملف صلاحيات مناسب من قسم الصلاحيات، ثم يمكنك تسجيل الدخول إلى القسم المسموح لك به.</p>
            <Link class="logout" method="post" :href="route('logout')" as="button">تسجيل الخروج</Link>
        </section>
    </main>
</template>

<style scoped>
.access-denied{min-height:100dvh;display:grid;place-items:center;padding:24px;background:#f5f7fb;color:#25304b;font-family:Cairo,system-ui,sans-serif}.access-card{width:min(100%,530px);padding:38px 32px;border:1px solid #e1e6f0;border-radius:24px;background:#fff;box-shadow:0 18px 45px rgba(39,52,84,.09);text-align:center}.access-icon{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 17px;border-radius:16px;background:#eeeafe;color:#6658df;font-size:30px;font-weight:900}.access-card h1{margin:0;color:#313252;font-size:21px;font-weight:900}.access-card p{margin:13px auto 23px;max-width:410px;color:#778099;font-size:13px;font-weight:700;line-height:2}.logout{min-height:40px;padding:9px 18px;border:0;border-radius:10px;color:#fff;background:#6d5ce8;font:800 12px Cairo,system-ui,sans-serif;cursor:pointer}
</style>
