// Members of the current tenant's team, returned by GET /api/v1/team.
// Used to populate the assignee selector on the order detail page.
export interface TeamMember {
    id: number
    name: string
    role: 'owner' | 'admin' | 'staff'
}
