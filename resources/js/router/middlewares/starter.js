/**
 * Allows an authenticated user to access the main view only if he owns at least one twofaccount.
 * Push to the starter view otherwise.
 */
export default async function starter({ to, next, nextMiddleware, stores }) {
    const { twofaccounts } = stores

    if (twofaccounts.isEmpty) {
        // E14: a rejected fetch used to leave navigation pending forever
        // (blank screen) — fall back to the starter view, which retries the
        // fetch itself, instead of never calling next().
        await twofaccounts.fetch().then(() => {
            if (twofaccounts.isEmpty) {
                next({ name: 'start' })
            }
            else nextMiddleware()
        }).catch(() => {
            next({ name: 'start' })
        })
    }
    else nextMiddleware()
}