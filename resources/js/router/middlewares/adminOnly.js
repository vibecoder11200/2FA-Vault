/**
 * Allows an authenticated user to access the route only if he has administrator rights
 */
export default async function adminOnly({ to, next, nextMiddleware, stores }) {
    const { user } = stores
    const { errorHandler } = stores

    if (! user.isAdmin) {
        // E1: build a proper error payload instead of dereferencing an
        // undefined `response` (TypeError) and never calling next().
        let err = new Error('unauthorized')
        err.response = { status: 403 }
        errorHandler.show(err)
        next(false)
    }
    else nextMiddleware()
}