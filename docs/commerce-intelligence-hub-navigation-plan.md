# Commerce Intelligence Hub  
## Navigation and Application Structure Implementation Plan

## Overview

We are updating the information architecture and navigation of our Laravel-based **Commerce Intelligence Hub** to support a broader customer intelligence and agentic delivery platform.

The application currently includes:

- Dashboard
- Business Dashboard
- Clients
- Integrations
- Customer Meetings
- Findings
- Knowledge Base
- Deployments
- Users and system administration

The goal is to reorganize these existing areas and introduce placeholders for future delivery, agent, financial-management, and project-intelligence capabilities.

The new navigation should follow the customer delivery lifecycle:

```text
Customer
→ Project
→ Intelligence
→ Delivery
→ Deployment
→ Spend
```

This phase should focus on navigation, route structure, permissions, project-level organization, and page scaffolding.

Do not build the full agentic development functionality yet.

Preserve all existing functionality and data.

---

# 1. New Main Navigation

Update the left sidebar to use these primary sections:

```text
Dashboard
Clients
Intelligence
Delivery
Deployments
Financials
Meetings
Administration
```

Each section should be expandable and collapsible.

The sidebar should remember the user's expanded or collapsed state when practical.

---

## Dashboard

```text
Dashboard
├── Agency Dashboard
└── Business Dashboard
```

Rename the existing general Dashboard page to **Agency Dashboard**.

The Agency Dashboard should remain the default landing page.

Do not significantly redesign dashboard metrics in this task. Only update labels and navigation placement as needed.

---

## Clients

```text
Clients
├── Clients
├── Projects
├── Contacts
├── Integrations
└── Environments
```

### Existing functionality

- Move the existing Clients page here.
- Move the existing Integrations page here.
- Preserve existing routes where possible.
- Add redirects when URLs change.

### New placeholder pages

Create initial index pages for:

- Projects
- Contacts
- Environments

Each placeholder page should include:

- Page title
- Short description
- Search field
- Filter area
- Empty-state message
- Primary action button where appropriate

Suggested primary actions:

- `Add Project`
- `Add Contact`
- `Add Environment`

These buttons do not need to open fully functional creation flows unless equivalent functionality already exists.

---

## Intelligence

```text
Intelligence
├── Findings
├── Knowledge Base
├── Project Briefs
└── Recommendations
```

### Existing functionality

- Keep Findings under Intelligence.
- Keep Knowledge Base under Intelligence.
- Preserve all existing Findings and Knowledge Base functionality.

### New placeholder pages

Create:

- Project Briefs
- Recommendations

### Project Briefs purpose

This area will eventually contain continuously updated intelligence about:

- Customer objectives
- Project scope
- Technical architecture
- Business rules
- Important decisions
- Risks
- Current priorities
- Agent instructions

For now, create a clear index page with placeholder project brief records or an empty state.

### Recommendations purpose

This area will eventually contain AI-generated:

- Business recommendations
- Technical recommendations
- Ecommerce recommendations
- Delivery recommendations

Create the page structure only.

---

## Delivery

```text
Delivery
├── Development Requests
├── Agent Runs
├── Pull Requests
├── Approvals
└── Testing
```

This is a new main section.

Create placeholder index pages for all five areas.

### Development Requests

This will eventually represent work originating from:

- Customer requests
- Jira issues
- Meetings
- Findings
- Internal team members
- Automated alerts

Suggested columns:

```text
Request
Client
Project
Source
Priority
Status
Owner
Updated
```

### Agent Runs

This will eventually display OpenHands and OpenCode execution sessions.

Suggested columns:

```text
Run
Client
Project
Task
Agent
Status
Started
Cost
```

### Pull Requests

This will eventually combine GitHub and Bitbucket pull requests.

Suggested columns:

```text
Pull Request
Provider
Repository
Project
Status
Pipeline
Reviewer
Updated
```

### Approvals

This will eventually serve as the central approval inbox.

Suggested approval types:

- Requirements approval
- Agent plan approval
- Pull-request review
- Staging approval
- Customer acceptance
- Production deployment approval

### Testing

This will eventually display:

- Unit tests
- Integration tests
- Playwright tests
- Pipeline checks
- Regression results
- Customer acceptance testing

These pages are placeholders only.

Do not integrate OpenHands, OpenCode, GitHub, Bitbucket, or Jira yet.

---

## Deployments

```text
Deployments
├── Release History
├── Deployment Approvals
└── Release Calendar
```

Move the current Deployments functionality into **Release History**.

Do not remove or rewrite existing deployment functionality.

Create placeholders for:

- Deployment Approvals
- Release Calendar

Use redirects or route aliases so existing bookmarked deployment URLs continue working.

Environments should live under Clients because they are part of each customer's project configuration.

Deployment history remains in the Deployments section.

---

## Financials

```text
Financials
├── Project Budgets
├── AI Spend
├── Infrastructure Spend
└── Billing Review
```

Create placeholder index pages for all four areas.

### Future purpose

Project Budgets will eventually track:

- Customer
- Project
- Budget bucket
- Included allowance
- Current consumption
- Warning threshold
- Hard limit

AI Spend will eventually receive model-usage costs from LiteLLM.

Infrastructure Spend will eventually receive GCP agent-runtime costs.

Billing Review will eventually combine:

```text
AI costs
+ GCP execution costs
+ other delivery costs
+ customer billing rules
```

Do not build LiteLLM or Google Cloud integrations in this task.

Use sample empty states rather than fabricated financial values.

---

## Meetings

```text
Meetings
└── Customer Meetings
```

Move the existing Customer Meetings page into this section.

Preserve all existing functionality.

Leave room for future pages such as:

- Meeting Preparation
- Follow-ups
- Decisions

Do not add these pages now unless needed to support the navigation architecture.

---

## Administration

```text
Administration
├── Users
├── Roles & Permissions
├── Integration Settings
├── Agent Settings
└── System Settings
```

Move current user-management functionality under Administration.

Create placeholders where pages do not already exist.

### Important distinction

There are two types of integrations.

#### Client integrations

Located under:

```text
Clients → Integrations
```

Examples:

- Customer Google Workspace
- Jira project
- Bitbucket workspace
- GitHub organization
- Shopify
- Magento
- Analytics platforms

#### Platform integration settings

Located under:

```text
Administration → Integration Settings
```

Examples:

- Global OAuth applications
- API configuration
- Webhook settings
- Provider credentials
- Default connector configuration

Do not mix these concepts.

---

# 2. Project-Level Navigation

Projects should become the primary working context of the application.

When a user opens a specific project, display a local project navigation bar or tab system:

```text
Overview
Intelligence
Work
Repositories
Deployments
Meetings
Spend
Settings
```

## Tab responsibilities

### Overview

Show high-level project information:

- Customer
- Project status
- Project owner
- Platform
- Current risks
- Active work
- Recent deployment
- Budget status

Use existing data where available.

Otherwise, use clearly labeled empty states.

### Intelligence

Future project-specific view of:

- Project brief
- Knowledge entries
- Decisions
- Findings
- Recommendations

### Work

Future project-specific view of:

- Development requests
- Jira issues
- Agent runs
- Approvals
- Testing

### Repositories

Future view of all GitHub and Bitbucket repositories connected to the project.

### Deployments

Filter deployment history to this project.

### Meetings

Filter customer meetings to this project where project relationships exist.

### Spend

Future project-level view of:

- AI spend
- Infrastructure spend
- Budget consumption
- Billable amount

### Settings

Future project configuration:

- Integrations
- Environments
- Repositories
- Engineering profile
- Budget rules
- Agent permissions

Create the routes and tab structure now.

Do not build the complete functionality for every tab.

---

# 3. Navigation Behavior

## Active states

The navigation must clearly show:

- Current main section
- Current submenu
- Current project tab

Use the application's existing active-state styling.

## Collapsible groups

Main menu groups should be collapsible.

Only one expanded section at a time is preferred unless the current design system supports multiple expanded sections cleanly.

## Responsive behavior

On smaller screens:

- Collapse the sidebar into a drawer.
- Keep project tabs horizontally scrollable or move them into a compact selector.
- Ensure all navigation is keyboard accessible.

## Icons

Use the application's existing icon library.

Suggested icon concepts:

```text
Dashboard       → dashboard or home
Clients         → building or users
Intelligence    → brain, lightbulb, or search
Delivery        → workflow, code, or activity
Deployments     → rocket or upload
Financials      → wallet, chart, or dollar sign
Meetings        → calendar
Administration  → settings or shield
```

Use a consistent icon style.

---

# 4. Routing Structure

Use named Laravel routes and organize them by domain.

Recommended route direction:

```text
/dashboard
/dashboard/business

/clients
/clients/{client}
/clients/{client}/projects

/projects
/projects/{project}
/projects/{project}/intelligence
/projects/{project}/work
/projects/{project}/repositories
/projects/{project}/deployments
/projects/{project}/meetings
/projects/{project}/spend
/projects/{project}/settings

/intelligence/findings
/intelligence/knowledge-base
/intelligence/project-briefs
/intelligence/recommendations

/delivery/requests
/delivery/agent-runs
/delivery/pull-requests
/delivery/approvals
/delivery/testing

/deployments
/deployments/approvals
/deployments/calendar

/financials/project-budgets
/financials/ai-spend
/financials/infrastructure-spend
/financials/billing-review

/meetings

/admin/users
/admin/roles
/admin/integrations
/admin/agents
/admin/settings
```

The exact URL structure may be adjusted to follow existing application conventions.

Preserve old routes through redirects when practical.

Do not break links from existing pages.

---

# 5. Permissions

Prepare the navigation to respect role-based permissions.

At minimum, support hiding menu items based on permissions.

Suggested permission groups:

```text
dashboard.view

clients.view
clients.manage

projects.view
projects.manage

intelligence.view
intelligence.manage

delivery.view
delivery.manage
delivery.approve

deployments.view
deployments.manage
deployments.approve

financials.view
financials.manage

meetings.view
meetings.manage

administration.view
administration.manage
```

Do not assume every customer-facing user can see:

- Internal agent runs
- Internal financial costs
- Repository details
- Internal recommendations
- Administration

Customer users should eventually receive a restricted project-level view.

For this phase, establish the permission structure without completing the entire customer portal.

---

# 6. Reusable UI Components

Use or create reusable components for:

- Sidebar section
- Sidebar menu item
- Page header
- Breadcrumbs
- Project tabs
- Empty state
- Status badge
- Filter bar
- Search input
- Summary card
- Data table
- Permission-aware navigation item

Avoid duplicating markup across pages.

Follow the existing application design system rather than introducing a new UI framework.

---

# 7. Breadcrumbs

Add consistent breadcrumbs to deeper pages.

Examples:

```text
Clients / Cambro / Website Optimization
```

```text
Clients / Cambro / Website Optimization / Intelligence
```

```text
Delivery / Agent Runs / AR-1042
```

```text
Deployments / Release History / DEP-209
```

Breadcrumbs should help users understand both the global module and project context.

---

# 8. Dashboard Preparation

Do not perform a full dashboard redesign, but organize the code so the Agency Dashboard can later include:

- Clients needing attention
- Open findings
- Active delivery requests
- Agent runs needing approval
- Failed pipelines
- Upcoming deployments
- Projects over budget
- AI and infrastructure spend

Keep current cards and tables functional.

Only change labels and navigation references required by the new structure.

---

# 9. Data and Migration Safety

This task should primarily change navigation and presentation.

Do not delete existing tables or data.

Before renaming routes, models, or database concepts:

1. Inspect current usage.
2. Preserve backward compatibility.
3. Add redirects when needed.
4. Avoid unnecessary database migrations.
5. Document any route or permission changes.

Existing Findings, Knowledge Base, Meetings, Clients, Integrations, Users, and Deployments must continue working.

---

# 10. Design Requirements

Match the current visual style shown in the application:

- Clean white interface
- Light borders
- Restrained use of color
- Clear headings
- Compact sidebar
- Readable table layouts
- Consistent cards and badges

Improve hierarchy without making the interface visually busy.

The navigation should feel simpler than the current version, even though more capabilities are being introduced.

Avoid showing every future page as a large dashboard card.

---

# 11. Implementation Phases

Complete the work in this order.

## Phase 1: Audit

Inspect:

- Existing route definitions
- Sidebar components
- Permissions
- Current page layouts
- Existing Clients, Findings, Knowledge Base, Meetings, Integrations, Deployments, and Users pages

Provide a brief implementation summary before modifying code.

## Phase 2: Navigation Restructuring

Implement the new primary sidebar and move existing menu items into the correct sections.

## Phase 3: Route Organization

Create routes for the new sections and redirects for changed existing routes.

## Phase 4: Placeholder Pages

Create polished placeholder index pages for the new modules.

## Phase 5: Project Navigation

Add project-level tabs and project-scoped route placeholders.

## Phase 6: Permissions

Make menu items and routes permission-aware using the existing authorization system.

## Phase 7: Quality Validation

Test:

- All existing pages still open
- Navigation active states
- Route redirects
- Sidebar collapse behavior
- Permission restrictions
- Responsive layout
- Project tabs
- Browser back and forward behavior

---

# 12. Acceptance Criteria

The task is complete when:

- The sidebar uses the new eight-section structure.
- Existing features continue to work.
- Findings and Knowledge Base appear under Intelligence.
- Deployments appears as its own main section.
- Development Requests, Agent Runs, Pull Requests, Approvals, and Testing appear under Delivery.
- Financial pages exist as placeholders.
- Projects are available globally and within each client.
- A project detail page contains the required tabs.
- GitHub and Bitbucket are represented generically as repositories, not as separate navigation sections.
- Client integrations and platform integration settings are clearly separated.
- Navigation respects user permissions.
- Existing URLs are redirected where required.
- Responsive behavior works.
- No existing customer or project data is removed.
- New placeholder pages clearly indicate that deeper functionality will be implemented later.
- The build, automated tests, linting, and static analysis pass.

---

# 13. Out of Scope

Do not implement the following yet:

- OpenHands integration
- OpenCode integration
- Agent execution
- GCP VM or container provisioning
- LiteLLM integration
- AI-cost synchronization
- Infrastructure-cost synchronization
- GitHub API integration
- Bitbucket API integration
- Jira synchronization
- Full Project Intelligence Hub ingestion
- Customer-facing approval workflows
- Automated deployments
- Production access for agents

The current objective is to establish the correct application structure so these features can be added cleanly afterward.

---

# Final Expected Result

The application should present a clear operating model:

```text
Dashboard
= Agency-wide visibility

Clients
= Customers, projects, integrations, and environments

Intelligence
= What we know and what we recommend

Delivery
= Work being requested, performed, tested, and approved

Deployments
= What is being released

Financials
= What delivery costs and how it should be billed

Meetings
= Customer communication and decisions

Administration
= Platform-level control
```

Implement this as a clean extension of the current application, not as a rewrite.
