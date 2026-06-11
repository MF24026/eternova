import api from './api'
import type { TeamMember } from '@/types/domain/Team'

// The team list endpoint returns a plain array, not a paginated envelope.
interface TeamListResponse {
    data: TeamMember[]
}

const TeamService = {
    /** Returns all members (owner, admin, staff) of the current tenant. */
    async list(): Promise<TeamMember[]> {
        const response = await api.get<TeamListResponse>('/team')
        return response.data.data
    },
}

export default TeamService
