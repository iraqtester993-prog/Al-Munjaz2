// Compatibility entry point for PWA installations registered before the
// canonical worker moved to /pwa/worker. Some shared-hosting stacks serve a
// physical sw.js before applying rewrite rules, so delegate to the versioned
// dynamic worker instead of keeping a second, stale cache implementation.
importScripts('/pwa/worker');
