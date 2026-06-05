import axios from 'axios'

// Set global Axios defaults so that any direct axios usage (e.g. the CSRF
// prefetch in services/api.ts) already carries the correct headers.
axios.defaults.withCredentials = true
axios.defaults.headers.common['Accept'] = 'application/json'
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'
