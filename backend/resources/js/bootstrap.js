import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

// A deployed PWA may briefly hold an expired CSRF token. Recover once by
// obtaining a fresh server-rendered login page rather than leaving a 419 in
// the browser console and blocking the user.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 419) {
            const key = 'almunjaz-csrf-retry-at';
            const lastRetry = Number(sessionStorage.getItem(key) || 0);
            if (Date.now() - lastRetry > 15_000) {
                sessionStorage.setItem(key, String(Date.now()));
                window.location.replace('/login');
            }
        }

        return Promise.reject(error);
    },
);

if (window.__locale) {
    document.documentElement.lang = window.__locale;
    document.documentElement.dir = (window.__locale === 'ar' || window.__locale === 'ku') ? 'rtl' : 'ltr';
}
