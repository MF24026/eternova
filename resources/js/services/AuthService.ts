import api from './api'
import type { Resource } from '@/types/api'
import type { User } from '@/types/domain/User'

const AuthService = {
    async login(email: string, password: string): Promise<void> {
        await api.post('/auth/login', { email, password })
    },

    async logout(): Promise<void> {
        await api.post('/auth/logout')
    },

    async register(name: string, email: string, password: string): Promise<void> {
        await api.post('/auth/register', { name, email, password })
    },

    async me(): Promise<User> {
        const response = await api.get<Resource<User>>('/me')
        return response.data.data
    },
}

export default AuthService
